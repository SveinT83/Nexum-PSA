<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleEvent;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Services\TicketRuleActionRetrySelector;
use App\Modules\Ticket\Services\TicketRuleAuditSanitizer;
use App\Modules\Ticket\Services\TicketRuleCompatibilityActionExecutor;
use App\Modules\Ticket\Services\TicketRuleEvidenceAccess;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use App\Modules\Ticket\Services\TicketRuleSchema2ActionExecutor;
use App\Modules\Ticket\Services\TicketRuleTicketState;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Retry one failed or not-run synchronous action position without replaying
 * successful siblings or trusting redacted audit JSON as executable input.
 */
final class RetryTicketRuleAction
{
    public function __construct(
        private readonly TicketRuleActionRetrySelector $selector,
        private readonly TicketRuleRuntimeGate $runtimeGate,
        private readonly TicketRuleTicketState $ticketState,
        private readonly TicketRuleSchema2ActionExecutor $schema2Executor,
        private readonly TicketRuleCompatibilityActionExecutor $compatibilityExecutor,
        private readonly TicketRuleAuditSanitizer $sanitizer,
        private readonly TicketRuleEvidenceAccess $evidenceAccess,
        private readonly DispatchTicketRuleMutationEvent $dispatchMutation,
    ) {}

    public function handle(
        TicketRuleRun $requestedRun,
        TicketRuleActionResult $requestedResult,
        User $requestedOperator,
    ): TicketRuleActionResult {
        if (! $this->runtimeGate->enabled()) {
            throw new RuntimeException('Ticket Rule v2 runtime authority is required for action retry.');
        }

        return DB::transaction(function () use (
            $requestedRun,
            $requestedResult,
            $requestedOperator,
        ): TicketRuleActionResult {
            $operator = $this->authorizedOperator($requestedOperator);
            $run = TicketRuleRun::query()->whereKey($requestedRun->id)->lockForUpdate()->firstOrFail();
            $run->load([
                'executions' => fn ($query) => $query->with('version'),
            ]);
            if ($this->evidenceAccess->runIsRestricted($run, $operator)) {
                throw new RuntimeException(
                    'The action retry is unavailable because its execution evidence is restricted.',
                );
            }
            $source = TicketRuleActionResult::query()
                ->whereKey($requestedResult->id)
                ->where('run_id', $run->id)
                ->lockForUpdate()
                ->firstOrFail();
            $ticket = Ticket::query()
                ->whereKey($run->ticket_id)
                ->whereNotNull('work_context_id')
                ->whereHas('workContext')
                ->lockForUpdate()
                ->firstOrFail();

            $this->runtimeGate->assertMutationCapabilities(true);

            if (! in_array($run->status, [
                TicketRuleRun::STATUS_FAILED,
                TicketRuleRun::STATUS_LOOP_BLOCKED,
            ], true)) {
                throw new RuntimeException('Only failed or loop-blocked Ticket Rule runs expose action retry.');
            }

            if (! $this->selector->isEligible($source, $ticket)) {
                throw new RuntimeException('This action position is no longer eligible for retry.');
            }

            $attempt = $this->selector->reserveRetryAttempt($source, $ticket);
            if (! $attempt) {
                throw new RuntimeException('The retry position changed while it was being reserved.');
            }

            $action = $this->selector->sourceAction($source);
            $version = $source->version()->firstOrFail();
            $event = $source->event()->firstOrFail();
            if ($action === null) {
                return $this->failAttempt($attempt, 'immutable_action_unavailable');
            }

            $expectedSnapshot = (int) $version->definition_schema_version
                === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION
                    ? $this->schema2Executor->snapshot($action)
                    : $this->compatibilityExecutor->snapshot($action);
            if (! hash_equals(
                TicketRuleStableJson::checksum($source->action_snapshot_json),
                TicketRuleStableJson::checksum($expectedSnapshot),
            )) {
                return $this->failAttempt($attempt, 'immutable_action_snapshot_mismatch');
            }

            $actor = $this->runtimeGate->requireExistingActor();
            $envelope = $this->retryEnvelope($ticket, $event, $run, $attempt, $operator, $actor);
            $startedAt = now();

            try {
                $result = DB::transaction(function () use (
                    $ticket,
                    $action,
                    $actor,
                    $envelope,
                    $attempt,
                    $version,
                    $operator,
                ): array {
                    $result = (int) $version->definition_schema_version
                        === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION
                            ? $this->schema2Executor->handle(
                                $ticket,
                                $action,
                                $actor,
                                $envelope,
                                true,
                                (string) $attempt->idempotency_key,
                            )
                            : $this->compatibilityExecutor->handle(
                                $ticket,
                                $action,
                                $actor,
                                $envelope,
                                true,
                            );

                    if (is_array($result['after_commit'] ?? null)) {
                        throw new TicketRuleActionFailure(
                            'delivery_retry_requires_reconciliation',
                            'External delivery retry requires the separate reconciliation boundary.',
                        );
                    }

                    foreach ((array) ($result['derived_events'] ?? []) as $derivedEvent) {
                        if (! $derivedEvent instanceof TicketRuleMutationEvent) {
                            throw new TicketRuleActionFailure(
                                'invalid_retry_derived_event',
                                'The retried action returned invalid derived-event evidence.',
                            );
                        }
                    }

                    $result['_derived_event_count'] = 0;
                    foreach ((array) ($result['derived_events'] ?? []) as $derivedEvent) {
                        $this->dispatchMutation->handle($ticket->refresh(), $derivedEvent, $operator);
                        $result['_derived_event_count']++;
                    }

                    return $result;
                });
            } catch (TicketRuleActionFailure $failure) {
                return $this->failAttempt($attempt, $failure->reasonCode, $failure->getMessage(), $startedAt);
            } catch (Throwable $failure) {
                return $this->failAttempt(
                    $attempt,
                    'unexpected_retry_failure',
                    $failure->getMessage(),
                    $startedAt,
                );
            }

            $derivedCount = (int) ($result['_derived_event_count'] ?? 0);
            unset($result['_derived_event_count']);
            $status = ($result['status'] ?? null) === 'succeeded'
                ? TicketRuleActionResult::STATUS_SUCCEEDED
                : TicketRuleActionResult::STATUS_NO_CHANGE;
            $completedAt = now();
            $attempt->forceFill([
                'status' => $status,
                'change_json' => (array) ($result['changes'] ?? []),
                'authorization_json' => array_merge(
                    (array) ($result['authorization'] ?? []),
                    [
                        'current_state_revalidated' => true,
                        'targets_revalidated' => true,
                        'retry_operator_id' => (int) $operator->id,
                        'derived_event_count' => $derivedCount,
                    ],
                ),
                'failure_code' => $result['reason_code'] ?? null,
                'failure_message' => null,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'duration_ms' => max(0, (int) $startedAt->diffInMilliseconds($completedAt)),
            ])->save();

            return $attempt->refresh();
        }, 3);
    }

    private function authorizedOperator(User $requested): User
    {
        $operator = User::query()->whereKey($requested->id)->first();

        if (! $operator
            || ! $operator->isActive()
            || ! $operator->can('ticket.view')
            || ! $operator->can('ticket.rule_retry')) {
            throw new RuntimeException('Ticket view and Ticket Rule retry permissions are required.');
        }

        return $operator;
    }

    private function retryEnvelope(
        Ticket $ticket,
        TicketRuleEvent $event,
        TicketRuleRun $run,
        TicketRuleActionResult $attempt,
        User $operator,
        User $actor,
    ): TicketRuleEventEnvelope {
        $facts = $this->ticketState->facts($ticket);
        $changedFields = collect((array) $event->changed_fields_json)
            ->filter(fn (mixed $field): bool => is_string($field) && $field !== '')
            ->unique()
            ->values()
            ->all();

        return new TicketRuleEventEnvelope(
            ticketId: (int) $ticket->id,
            eventKey: (string) $event->event_key,
            sourceChannel: 'ticket_rule_retry',
            sourceAction: 'RetryTicketRuleAction',
            changedFields: $changedFields,
            before: (array) $event->before_json,
            after: (array) $event->after_json,
            facts: array_merge($facts, [
                'event_source_channel' => 'ticket_rule_retry',
                'event_source_action' => 'RetryTicketRuleAction',
            ]),
            initiatorType: 'user',
            initiatorId: (int) $operator->id,
            automationActorId: (int) $actor->id,
            correlationUuid: (string) Str::uuid(),
            causationUuid: (string) $run->correlation_uuid,
            parentEventId: (int) $event->id,
            parentActionResultId: (int) $attempt->retry_of_id,
            chainDepth: (int) $event->chain_depth + 1,
            occurredAt: CarbonImmutable::now(),
            fingerprint: TicketRuleStableJson::checksum([
                'retry_action_result_id' => (int) $attempt->id,
                'ticket_state' => $facts,
            ]),
            idempotencyKey: (string) $attempt->idempotency_key,
        );
    }

    private function failAttempt(
        TicketRuleActionResult $attempt,
        string $reasonCode,
        ?string $safeMessage = null,
        mixed $startedAt = null,
    ): TicketRuleActionResult {
        $startedAt ??= now();
        $completedAt = now();
        $attempt->forceFill([
            'status' => TicketRuleActionResult::STATUS_FAILED,
            'change_json' => [],
            'authorization_json' => [
                'allowed' => false,
                'current_state_revalidated' => true,
            ],
            'failure_code' => $reasonCode,
            'failure_message' => $safeMessage === null
                ? null
                : Str::limit((string) $this->sanitizer->message($safeMessage), 500, ''),
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'duration_ms' => max(0, (int) $startedAt->diffInMilliseconds($completedAt)),
        ])->save();

        return $attempt->refresh();
    }
}
