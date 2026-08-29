<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\DispatchTicketRuleAfterCommit;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleAfterCommitResult;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleEvent;
use App\Modules\Ticket\Models\TicketRuleExecution;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleBranchFailure;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleDerivedEventIdentity;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleExecutionResult;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class TicketRuleExecutionCoordinator
{
    private const EVENT_FIELDS_CHANGED = 'ticket.fields_changed';

    private const EVENT_TAGS_CHANGED = 'ticket.tags_changed';

    public function __construct(
        private readonly TicketRuleRuntimeGate $runtimeGate,
        private readonly TicketRuleFrozenPublishedSet $frozenPublishedSet,
        private readonly TicketRuleConditionEvaluator $conditionEvaluator,
        private readonly TicketRuleSchema2ConditionEvaluator $schema2ConditionEvaluator,
        private readonly TicketRuleCompatibilityActionExecutor $compatibilityActionExecutor,
        private readonly TicketRuleSchema2ActionExecutor $schema2ActionExecutor,
        private readonly TicketRulePublishedDefinitionValidator $definitionValidator,
        private readonly TicketRuleTriggerRegistry $triggerRegistry,
        private readonly TicketRuleAuditSanitizer $sanitizer,
        private readonly TicketRuleTicketState $ticketState,
    ) {}

    /** @param array<string, mixed> $context */
    public function executeCreated(Ticket $ticket, array $context, ?User $initiator): TicketRuleExecutionResult
    {
        [$ticket, $actor, $fence, $frozen] = $this->prepareRoot($ticket);
        $envelope = TicketRuleEventEnvelope::created($ticket, $context, $initiator, $actor);

        return $this->executeRoot($ticket, $envelope, $actor, $fence, $frozen);
    }

    public function executeMutation(
        Ticket $ticket,
        TicketRuleMutationEvent $event,
        ?User $initiator,
    ): TicketRuleExecutionResult {
        if ((int) $ticket->id !== $event->ticketId) {
            throw new RuntimeException('The Ticket mutation event belongs to a different Ticket.');
        }

        [$ticket, $actor, $fence, $frozen] = $this->prepareRoot($ticket);
        $envelope = TicketRuleEventEnvelope::mutation($ticket, $event, $initiator, $actor);
        $result = $this->executeRoot($ticket, $envelope, $actor, $fence, $frozen);
        $finalized = $this->finalizeCreated($ticket, $result);

        return new TicketRuleExecutionResult(
            $finalized,
            $result->terminalStatus,
            $result->deliveries,
            $result->counters,
            $result->summary,
        );
    }

    /**
     * Execute one separately previewed full rerun as a new immutable root.
     * The caller holds the source-run lock and supplies a unique delivery
     * identity; repeated submission therefore resolves to the same linked run.
     */
    public function executeFullRerun(
        Ticket $ticket,
        TicketRuleMutationEvent $event,
        User $initiator,
        TicketRuleRun $requestedSource,
    ): TicketRuleExecutionResult {
        if ((int) $ticket->id !== $event->ticketId
            || $event->eventKey !== TicketRuleDefinitionRegistry::TRIGGER_CREATED) {
            throw new RuntimeException('The full rerun event does not match the source Ticket creation boundary.');
        }

        $source = TicketRuleRun::query()
            ->whereKey($requestedSource->id)
            ->where('ticket_id', $ticket->id)
            ->lockForUpdate()
            ->firstOrFail();
        if (! in_array($source->status, [
            TicketRuleRun::STATUS_SUCCEEDED,
            TicketRuleRun::STATUS_FAILED,
            TicketRuleRun::STATUS_NO_CHANGE,
            TicketRuleRun::STATUS_LOOP_BLOCKED,
        ], true)) {
            throw new RuntimeException('A terminal source run is required for full rerun.');
        }

        [$ticket, $actor, $fence, $frozen] = $this->prepareRoot($ticket);
        $envelope = TicketRuleEventEnvelope::mutation($ticket, $event, $initiator, $actor);
        $result = $this->executeRoot($ticket, $envelope, $actor, $fence, $frozen);
        $run = TicketRuleRun::query()->whereKey($result->run->id)->lockForUpdate()->firstOrFail();

        if ($run->status !== TicketRuleRun::STATUS_RUNNING) {
            if ($run->mode !== 'full_rerun'
                || (int) $run->retry_of_run_id !== (int) $source->id) {
                throw new RuntimeException('The full-rerun identity is already owned by another execution.');
            }

            return new TicketRuleExecutionResult(
                $run,
                $run->status,
                [],
                (array) $run->counters_json,
                (array) $run->safe_summary_json,
            );
        }

        $nextAttempt = (int) TicketRuleRun::query()
            ->where(function ($query) use ($source): void {
                $query->whereKey($source->id)
                    ->orWhere('retry_of_run_id', $source->id);
            })
            ->max('attempt_number') + 1;
        $run->forceFill([
            'mode' => 'full_rerun',
            'attempt_number' => max(2, $nextAttempt),
            'retry_of_run_id' => $source->id,
        ])->save();

        $finalized = $this->finalizeCreated($ticket, $result);

        return new TicketRuleExecutionResult(
            $finalized,
            $result->terminalStatus,
            $result->deliveries,
            $result->counters,
            $result->summary,
        );
    }

    /**
     * @return array{0: Ticket, 1: User, 2: TicketRuleAuthorityFence, 3: array{versions: Collection<int, TicketRuleVersion>, version_ids: list<int>, checksum: string}}
     */
    private function prepareRoot(Ticket $ticket): array
    {
        if (! $this->runtimeGate->enabled()) {
            throw new RuntimeException('Ticket Rule v2 runtime authority is not enabled.');
        }

        $this->runtimeGate->assertMutationCapabilities(true);
        $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        $actor = $this->runtimeGate->requireExistingActor();

        // Catalog mutations use this same fence. Holding it through capture
        // gives the run one coherent generation, checksum, and version set.
        $fence = TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->sharedLock()
            ->firstOrFail();

        if ($fence->runtime_authority !== TicketRuleAuthorityFence::AUTHORITY_V2) {
            throw new RuntimeException('Ticket Rule v2 authority changed during Ticket mutation.');
        }

        return [$ticket, $actor, $fence, $this->frozenPublishedSet->capture()];
    }

    /**
     * @param  array{versions: Collection<int, TicketRuleVersion>, version_ids: list<int>, checksum: string}  $frozen
     */
    private function executeRoot(
        Ticket $ticket,
        TicketRuleEventEnvelope $envelope,
        User $actor,
        TicketRuleAuthorityFence $fence,
        array $frozen,
    ): TicketRuleExecutionResult {
        $existing = TicketRuleRun::query()
            ->where('root_idempotency_key', $envelope->idempotencyKey)
            ->first();

        if ($existing) {
            if ($existing->status === TicketRuleRun::STATUS_RUNNING) {
                throw new RuntimeException('An incomplete Ticket Rule run already owns this created event.');
            }

            return new TicketRuleExecutionResult(
                $existing,
                $existing->status,
                [],
                $existing->counters_json ?? [],
                $existing->safe_summary_json ?? [],
            );
        }

        $this->assertFrozenDefinitions($frozen['versions']);

        $limits = $this->limits();
        $counters = [
            'events' => 1,
            'evaluated_rules' => 0,
            'actions' => 0,
            'loop_blocks' => 0,
            'failed_executions' => 0,
        ];
        $run = TicketRuleRun::query()->create([
            'ticket_id' => $ticket->id,
            'root_event_key' => $envelope->eventKey,
            'source_channel' => $envelope->sourceChannel,
            'source_action' => $envelope->sourceAction,
            'initiator_type' => $envelope->initiatorType,
            'initiator_id' => $envelope->initiatorId,
            'automation_actor_id' => $actor->id,
            'correlation_uuid' => $envelope->correlationUuid,
            'causation_uuid' => $envelope->causationUuid,
            'root_idempotency_key' => $envelope->idempotencyKey,
            'mode' => 'runtime',
            'attempt_number' => 1,
            'retry_of_run_id' => null,
            'authority_generation' => $fence->catalog_generation,
            'authority_checksum' => $fence->catalog_checksum,
            'published_set_checksum' => $frozen['checksum'],
            'published_version_ids' => $frozen['version_ids'],
            'status' => TicketRuleRun::STATUS_RUNNING,
            'limits_json' => $limits,
            'counters_json' => $counters,
            'started_at' => now(),
        ]);
        $rootEvent = TicketRuleEvent::query()->create($envelope->persistence() + [
            'run_id' => $run->id,
            'sequence' => 1,
            'status' => TicketRuleEvent::STATUS_QUEUED,
        ]);

        $runtimeEnvelopes = [$rootEvent->id => $envelope];
        $deliveries = [];
        $hadEffect = false;
        $hadFailure = false;
        $loopBlocked = false;

        while ($event = TicketRuleEvent::query()
            ->where('run_id', $run->id)
            ->where('status', TicketRuleEvent::STATUS_QUEUED)
            ->orderBy('sequence')
            ->first()) {
            $runtimeEnvelope = $runtimeEnvelopes[$event->id] ?? null;

            if (! $runtimeEnvelope) {
                throw new RuntimeException('Runtime-only Ticket Rule facts are unavailable for a queued event.');
            }

            $outcome = $this->processEvent(
                $ticket,
                $run,
                $event,
                $runtimeEnvelope,
                $frozen['versions'],
                $actor,
                $limits,
                $counters,
                $deliveries,
                $runtimeEnvelopes,
            );
            $hadEffect = $hadEffect || $outcome['effect'];
            $hadFailure = $hadFailure || $outcome['failure'];
            $loopBlocked = $loopBlocked || $outcome['loop_blocked'];

            if ($outcome['halt']) {
                TicketRuleEvent::query()
                    ->where('run_id', $run->id)
                    ->where('status', TicketRuleEvent::STATUS_QUEUED)
                    ->get()
                    ->each(fn (TicketRuleEvent $queued) => $queued->forceFill([
                        'loop_reason_code' => $outcome['loop_reason_code'],
                        'blocked_event_fingerprint' => $outcome['blocked_event_fingerprint'],
                        'status' => TicketRuleEvent::STATUS_LOOP_BLOCKED,
                        'processed_at' => now(),
                    ])->save());
                break;
            }
        }

        $terminalStatus = $loopBlocked
            ? TicketRuleRun::STATUS_LOOP_BLOCKED
            : ($hadFailure
                ? TicketRuleRun::STATUS_FAILED
                : ($hadEffect ? TicketRuleRun::STATUS_SUCCEEDED : TicketRuleRun::STATUS_NO_CHANGE));
        $summary = [
            'terminal_status' => $terminalStatus,
            'published_version_count' => count($frozen['version_ids']),
            'event_count' => $counters['events'],
            'evaluated_rule_count' => $counters['evaluated_rules'],
            'action_count' => $counters['actions'],
            'failed_execution_count' => $counters['failed_executions'],
            'loop_block_count' => $counters['loop_blocks'],
        ];
        $summary += $this->summaryDetails($run);

        return new TicketRuleExecutionResult($run, $terminalStatus, $deliveries, $counters, $summary);
    }

    public function finalizeCreated(Ticket $ticket, TicketRuleExecutionResult $result): TicketRuleRun
    {
        $run = TicketRuleRun::query()->whereKey($result->run->id)->lockForUpdate()->firstOrFail();

        if ($run->status !== TicketRuleRun::STATUS_RUNNING) {
            return $run;
        }

        TicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'ticket_rule_run_id' => $run->id,
            'actor_id' => $run->automation_actor_id,
            'type' => 'automation_run',
            'message' => 'Ticket Rule automation completed.',
            'after' => $result->summary,
        ]);

        $completedAt = now();
        $run->forceFill([
            'status' => $result->terminalStatus,
            'termination_reason' => $result->terminalStatus === TicketRuleRun::STATUS_LOOP_BLOCKED
                ? 'execution_budget_or_loop_blocked'
                : null,
            'counters_json' => $result->counters,
            'safe_summary_json' => $result->summary,
            'completed_at' => $completedAt,
            'duration_ms' => max(0, (int) $run->started_at->diffInMilliseconds($completedAt)),
        ])->save();

        foreach ($result->deliveries as $delivery) {
            DB::afterCommit(static function () use ($delivery): void {
                app(DispatchTicketRuleAfterCommit::class)->signal(
                    $delivery['delivery_id'],
                    $delivery['payload'],
                );
            });
        }

        return $run->refresh();
    }

    /**
     * @param  Collection<int, TicketRuleVersion>  $versions
     * @param  array<string, int>  $limits
     * @param  array<string, int>  $counters
     * @param  list<array{delivery_id: int, payload: array<string, mixed>}>  $deliveries
     * @param  array<int, TicketRuleEventEnvelope>  $runtimeEnvelopes
     * @return array{effect: bool, failure: bool, loop_blocked: bool, halt: bool, loop_reason_code: string|null, blocked_event_fingerprint: string|null}
     */
    private function processEvent(
        Ticket $ticket,
        TicketRuleRun $run,
        TicketRuleEvent $event,
        TicketRuleEventEnvelope $envelope,
        Collection $versions,
        User $actor,
        array $limits,
        array &$counters,
        array &$deliveries,
        array &$runtimeEnvelopes,
    ): array {
        $eventEffect = false;
        $eventFailure = false;
        $eventLoopBlocked = false;
        $halt = false;
        $loopReasonCode = null;
        $blockedEventFingerprint = null;

        foreach ($versions as $order => $version) {
            $definition = is_array($version->definition_json) ? $version->definition_json : [];
            if (! $this->definitionIsRelevant($version, $definition, $envelope)) {
                continue;
            }

            $triggerRelevant = true;

            if ($counters['evaluated_rules'] >= $limits['max_evaluated_rules']) {
                $eventLoopBlocked = true;
                $halt = true;
                $loopReasonCode = TicketRuleEvent::LOOP_REASON_EVALUATED_RULE_BUDGET_EXCEEDED;
                $counters['loop_blocks']++;
                break;
            }

            $counters['evaluated_rules']++;
            $startedAt = now();
            $execution = TicketRuleExecution::query()->create([
                'run_id' => $run->id,
                'event_id' => $event->id,
                'ticket_rule_id' => $version->ticket_rule_id,
                'rule_version_id' => $version->id,
                'order_position' => $order + 1,
                'attempt_number' => 1,
                'retry_of_id' => null,
                'precondition_fingerprint' => $this->ticketState->fingerprint($ticket),
                'idempotency_key' => TicketRuleStableJson::checksum([
                    'event_id' => $event->id,
                    'version_id' => $version->id,
                    'attempt' => 1,
                ]),
                'definition_checksum' => $version->definition_checksum,
                'status' => TicketRuleExecution::STATUS_RUNNING,
                'trigger_relevant' => $triggerRelevant,
                'conditions_matched' => false,
                'started_at' => $startedAt,
            ]);

            $validation = $this->definitionValidator->validateStored($definition);
            if (($validation['status'] ?? null) !== TicketRulePublishedDefinitionValidator::STATUS_VALID
                || (int) $version->definition_schema_version !== (int) ($validation['schema_version'] ?? 0)
                || TicketRuleStableJson::checksum($definition) !== $version->definition_checksum
                || ($validation['checksum'] ?? null) !== $version->definition_checksum) {
                $this->completeExecution($execution, $startedAt, [
                    'status' => TicketRuleExecution::STATUS_FAILED,
                    'failure_code' => 'invalid_published_definition',
                    'failure_message' => 'Published Ticket Rule definition evidence is invalid.',
                ]);
                $eventFailure = true;
                $counters['failed_executions']++;

                continue;
            }

            $definition = $validation['definition'];
            $evaluation = (int) $version->definition_schema_version
                === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION
                    ? $this->schema2ConditionEvaluator->evaluate($definition, $envelope->facts)
                    : $this->conditionEvaluator->evaluate($definition, $envelope->facts);
            if (! ($evaluation['valid'] ?? false)) {
                $this->completeExecution($execution, $startedAt, [
                    'status' => TicketRuleExecution::STATUS_FAILED,
                    'condition_evidence_json' => $evaluation,
                    'failure_code' => $evaluation['reason_code'] ?? 'invalid_runtime_definition',
                    'failure_message' => 'Published Ticket Rule conditions failed runtime validation.',
                ]);
                $eventFailure = true;
                $counters['failed_executions']++;

                continue;
            }

            $matched = (bool) $evaluation['passed'];
            $branch = $matched ? 'then' : 'else';
            $actions = (array) ($definition[$matched ? 'then_actions' : 'else_actions'] ?? []);
            $stopRequested = ($matched || $actions !== []) && (bool) data_get($definition, 'flow.stop_processing', false);

            if (! $matched && $actions === []) {
                $this->completeExecution($execution, $startedAt, [
                    'status' => TicketRuleExecution::STATUS_UNMATCHED,
                    'conditions_matched' => false,
                    'selected_branch' => $branch,
                    'condition_evidence_json' => $evaluation,
                    'stop_requested' => false,
                    'stop_applied' => false,
                ]);

                continue;
            }

            $branchOutcome = $this->executeBranch(
                $ticket,
                $run,
                $event,
                $envelope,
                $execution,
                $version,
                $versions,
                $actor,
                $branch,
                $actions,
                $limits,
                $counters,
                $deliveries,
                $runtimeEnvelopes,
            );
            $status = $branchOutcome['status'];
            $stopApplied = $stopRequested && $status !== TicketRuleExecution::STATUS_FAILED;
            $this->completeExecution($execution, $startedAt, [
                'status' => $status,
                'conditions_matched' => $matched,
                'selected_branch' => $branch,
                'condition_evidence_json' => $evaluation,
                'change_summary_json' => $branchOutcome['summary'],
                'stop_requested' => $stopRequested,
                'stop_applied' => $stopApplied,
                'failure_code' => $branchOutcome['failure_code'],
                'failure_message' => $branchOutcome['failure_message'],
            ]);

            $eventEffect = $eventEffect || $branchOutcome['effect'];
            $eventFailure = $eventFailure || $status === TicketRuleExecution::STATUS_FAILED;
            $eventLoopBlocked = $eventLoopBlocked || $branchOutcome['loop_blocked'];
            $halt = $halt || $branchOutcome['halt'];
            if ($branchOutcome['loop_blocked']) {
                $loopReasonCode = $branchOutcome['loop_reason_code'];
                $blockedEventFingerprint = $branchOutcome['blocked_event_fingerprint'];
            }

            if ($matched) {
                TicketRule::query()
                    ->whereKey($version->ticket_rule_id)
                    ->increment('hit_count', 1, ['last_hit_at' => now()]);
            }

            if ($status === TicketRuleExecution::STATUS_FAILED) {
                $counters['failed_executions']++;
            }

            if ($stopApplied || $halt) {
                break;
            }
        }

        $event->forceFill([
            'loop_reason_code' => $loopReasonCode,
            'blocked_event_fingerprint' => $blockedEventFingerprint,
            'status' => ($eventLoopBlocked || $halt)
                ? TicketRuleEvent::STATUS_LOOP_BLOCKED
                : ($eventEffect ? TicketRuleEvent::STATUS_PROCESSED : TicketRuleEvent::STATUS_NO_CHANGE),
            'processed_at' => now(),
        ])->save();

        return [
            'effect' => $eventEffect,
            'failure' => $eventFailure,
            'loop_blocked' => $eventLoopBlocked || $halt,
            'halt' => $halt,
            'loop_reason_code' => $loopReasonCode,
            'blocked_event_fingerprint' => $blockedEventFingerprint,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, int>  $limits
     * @param  array<string, int>  $counters
     * @param  list<array{delivery_id: int, payload: array<string, mixed>}>  $deliveries
     * @param  array<int, TicketRuleEventEnvelope>  $runtimeEnvelopes
     * @return array{status: string, effect: bool, loop_blocked: bool, halt: bool, loop_reason_code: string|null, blocked_event_fingerprint: string|null, summary: array<string, mixed>, failure_code: string|null, failure_message: string|null}
     */
    private function executeBranch(
        Ticket $ticket,
        TicketRuleRun $run,
        TicketRuleEvent $event,
        TicketRuleEventEnvelope $envelope,
        TicketRuleExecution $execution,
        TicketRuleVersion $version,
        Collection $versions,
        User $actor,
        string $branch,
        array $actions,
        array $limits,
        array &$counters,
        array &$deliveries,
        array &$runtimeEnvelopes,
    ): array {
        $startedAt = microtime(true);

        try {
            $completed = DB::transaction(function () use (
                $ticket,
                $envelope,
                $actor,
                $actions,
                $limits,
                $execution,
                $version,
                $branch,
                &$counters,
            ): array {
                $results = [];

                foreach ($actions as $position => $action) {
                    $precondition = $this->ticketState->fingerprint($ticket);

                    if ($counters['actions'] >= $limits['max_actions']) {
                        throw new TicketRuleBranchFailure(
                            $results,
                            (int) $position,
                            'action_budget_exceeded',
                            $precondition,
                            'The Ticket Rule action budget was exhausted.',
                        );
                    }

                    $counters['actions']++;
                    $actionStarted = microtime(true);

                    try {
                        $action = is_array($action) ? $action : [];
                        $actionIdempotencyKey = TicketRuleStableJson::checksum([
                            'execution_id' => $execution->id,
                            'branch' => $branch,
                            'position' => (int) $position,
                            'attempt' => 1,
                        ]);
                        $result = (int) $version->definition_schema_version
                            === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION
                                ? $this->schema2ActionExecutor->handle(
                                    $ticket,
                                    $action,
                                    $actor,
                                    $envelope,
                                    true,
                                    $actionIdempotencyKey,
                                )
                                : $this->compatibilityActionExecutor->handle(
                                    $ticket,
                                    $action,
                                    $actor,
                                    $envelope,
                                    true,
                                );
                        if ((int) $version->definition_schema_version
                            === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
                            foreach ((array) ($result['derived_events'] ?? []) as $derivedEvent) {
                                if (! $derivedEvent instanceof TicketRuleMutationEvent) {
                                    throw new TicketRuleActionFailure(
                                        'invalid_derived_event',
                                        'The Ticket Rule action returned an invalid derived event.',
                                    );
                                }
                            }
                        }
                    } catch (TicketRuleActionFailure $failure) {
                        throw new TicketRuleBranchFailure(
                            $results,
                            (int) $position,
                            $failure->reasonCode,
                            $precondition,
                            $failure->getMessage(),
                            $failure,
                        );
                    } catch (Throwable $failure) {
                        throw new TicketRuleBranchFailure(
                            $results,
                            (int) $position,
                            'unexpected_action_failure',
                            $precondition,
                            $this->sanitizer->message($failure->getMessage()) ?? 'Action failure.',
                            $failure,
                        );
                    }

                    $results[] = [
                        'position' => (int) $position,
                        'action' => is_array($action) ? $action : [],
                        'result' => $result,
                        'precondition_fingerprint' => $precondition,
                        'post_action_facts' => $this->ticketState->facts($ticket),
                        'duration_ms' => max(0, (int) round((microtime(true) - $actionStarted) * 1000)),
                    ];
                }

                return $results;
            });
        } catch (TicketRuleBranchFailure $failure) {
            $ticket->refresh();
            $this->persistFailedBranch(
                $run,
                $event,
                $execution,
                $version,
                $branch,
                $actions,
                $failure,
            );

            if ($failure->reasonCode === 'action_budget_exceeded') {
                $counters['loop_blocks']++;
            }

            return [
                'status' => TicketRuleExecution::STATUS_FAILED,
                'effect' => false,
                'loop_blocked' => $failure->reasonCode === 'action_budget_exceeded',
                'halt' => $failure->reasonCode === 'action_budget_exceeded',
                'loop_reason_code' => $failure->reasonCode === 'action_budget_exceeded'
                    ? TicketRuleEvent::LOOP_REASON_ACTION_BUDGET_EXCEEDED
                    : null,
                'blocked_event_fingerprint' => null,
                'summary' => ['rolled_back_action_count' => count($failure->completedResults)],
                'failure_code' => $failure->reasonCode,
                'failure_message' => $failure->getMessage(),
            ];
        }

        $effect = false;
        $queuedDeliveryCount = 0;
        $changeFields = [];
        $branchLoopBlocked = false;
        $branchLoopReasonCode = null;
        $branchBlockedEventFingerprint = null;

        foreach ($completed as $item) {
            $result = $item['result'];
            $action = $item['action'];
            $actionResult = $this->persistActionResult(
                $run,
                $event,
                $execution,
                $version,
                $branch,
                $item,
            );
            $effect = $effect || in_array($result['status'] ?? null, ['succeeded', 'queued'], true);
            $changeFields = array_values(array_unique(array_merge(
                $changeFields,
                array_keys((array) ($result['changes'] ?? [])),
            )));

            if (is_array($result['after_commit'] ?? null)) {
                $payload = array_merge($result['after_commit'], [
                    'ticket_rule_id' => (int) $version->ticket_rule_id,
                    'ticket_rule_name' => (string) $version->rule?->name,
                    'ticket_rule_action_index' => (int) $item['position'],
                ]);
                $delivery = $this->persistDelivery($run, $ticket, $actionResult, $payload);
                $deliveries[] = ['delivery_id' => $delivery->id, 'payload' => $payload];
                $queuedDeliveryCount++;
            }

            if (($result['status'] ?? null) !== 'succeeded') {
                continue;
            }

            $blockedEvents = [];
            if ((int) $version->definition_schema_version
                === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
                foreach ((array) ($result['derived_events'] ?? []) as $derivedEvent) {
                    if ($derivedEvent instanceof TicketRuleMutationEvent) {
                        $blockedEvents[] = $this->enqueueDerivedMutationEvent(
                            $ticket,
                            $run,
                            $event,
                            $envelope,
                            $actionResult,
                            $derivedEvent,
                            $versions,
                            (array) $item['post_action_facts'],
                            $runtimeEnvelopes,
                            $limits,
                        );
                    }
                }
            } elseif (! empty($result['changes'])) {
                $blockedEvents[] = $this->enqueueDerivedEvent(
                    $ticket,
                    $run,
                    $event,
                    $envelope,
                    $actionResult,
                    (string) ($action['type'] ?? 'unknown'),
                    (array) $result['changes'],
                    $versions,
                    (array) $item['post_action_facts'],
                    $runtimeEnvelopes,
                    $limits,
                );
            }

            foreach ($blockedEvents as $blocked) {
                if (is_array($blocked)) {
                    $counters['events']++;
                }

                if (($blocked['blocked'] ?? false) === true) {
                    $counters['loop_blocks']++;
                    $branchLoopBlocked = true;
                    $branchLoopReasonCode = $blocked['reason_code'];
                    $branchBlockedEventFingerprint = $blocked['blocked_event_fingerprint'];
                }
            }
        }

        $status = $effect
            ? TicketRuleExecution::STATUS_SUCCEEDED
            : TicketRuleExecution::STATUS_NO_CHANGE;

        return [
            'status' => $status,
            'effect' => $effect,
            'loop_blocked' => $branchLoopBlocked,
            'halt' => $branchLoopBlocked,
            'loop_reason_code' => $branchLoopReasonCode,
            'blocked_event_fingerprint' => $branchBlockedEventFingerprint,
            'summary' => [
                'action_count' => count($completed),
                'changed_fields' => $changeFields,
                'queued_delivery_count' => $queuedDeliveryCount,
                'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
            ],
            'failure_code' => null,
            'failure_message' => null,
        ];
    }

    /** @param array<string, mixed> $item */
    private function persistActionResult(
        TicketRuleRun $run,
        TicketRuleEvent $event,
        TicketRuleExecution $execution,
        TicketRuleVersion $version,
        string $branch,
        array $item,
    ): TicketRuleActionResult {
        $position = (int) $item['position'];
        $action = $item['action'];
        $result = $item['result'];
        $positionKey = TicketRuleStableJson::checksum([
            'execution_id' => $execution->id,
            'branch' => $branch,
            'position' => $position,
        ]);

        return TicketRuleActionResult::query()->create([
            'run_id' => $run->id,
            'event_id' => $event->id,
            'execution_id' => $execution->id,
            'ticket_id' => $run->ticket_id,
            'ticket_rule_id' => $version->ticket_rule_id,
            'rule_version_id' => $version->id,
            'branch' => $branch,
            'position' => $position,
            'action_type' => (string) ($action['type'] ?? 'unknown'),
            'position_key' => $positionKey,
            'attempt_number' => 1,
            'retry_of_id' => null,
            'precondition_fingerprint' => $item['precondition_fingerprint'],
            'idempotency_key' => TicketRuleStableJson::checksum([$positionKey, 'attempt' => 1]),
            'action_snapshot_json' => $this->actionSnapshot($version, $action),
            'status' => $result['status'],
            'change_json' => $result['changes'] ?? [],
            'authorization_json' => array_merge(
                (array) ($result['authorization'] ?? []),
                [
                    'assignment_decision' => (bool) ($result['assignment_decision'] ?? false),
                    'sla_decision' => (bool) ($result['sla_decision'] ?? false),
                ],
            ),
            'failure_code' => $result['reason_code'] ?? null,
            'started_at' => now(),
            'completed_at' => now(),
            'duration_ms' => $item['duration_ms'],
        ]);
    }

    /** @param list<array<string, mixed>> $actions */
    private function persistFailedBranch(
        TicketRuleRun $run,
        TicketRuleEvent $event,
        TicketRuleExecution $execution,
        TicketRuleVersion $version,
        string $branch,
        array $actions,
        TicketRuleBranchFailure $failure,
    ): void {
        $completedByPosition = collect($failure->completedResults)->keyBy('position');

        foreach ($actions as $position => $action) {
            $positionKey = TicketRuleStableJson::checksum([
                'execution_id' => $execution->id,
                'branch' => $branch,
                'position' => (int) $position,
            ]);
            $completed = $completedByPosition->get($position);
            $status = $position < $failure->failedPosition
                ? TicketRuleActionResult::STATUS_ROLLED_BACK
                : ($position === $failure->failedPosition
                    ? TicketRuleActionResult::STATUS_FAILED
                    : TicketRuleActionResult::STATUS_NOT_RUN);

            TicketRuleActionResult::query()->create([
                'run_id' => $run->id,
                'event_id' => $event->id,
                'execution_id' => $execution->id,
                'ticket_id' => $run->ticket_id,
                'ticket_rule_id' => $version->ticket_rule_id,
                'rule_version_id' => $version->id,
                'branch' => $branch,
                'position' => (int) $position,
                'action_type' => (string) (($action['type'] ?? null) ?: 'unknown'),
                'position_key' => $positionKey,
                'attempt_number' => 1,
                'retry_of_id' => null,
                'precondition_fingerprint' => $completed['precondition_fingerprint']
                    ?? ($position === $failure->failedPosition
                        ? $failure->failedPreconditionFingerprint
                        : $this->ticketState->fingerprint($run->ticket)),
                'idempotency_key' => TicketRuleStableJson::checksum([$positionKey, 'attempt' => 1]),
                'action_snapshot_json' => $this->actionSnapshot(
                    $version,
                    is_array($action) ? $action : [],
                ),
                'status' => $status,
                'change_json' => $completed['result']['changes'] ?? null,
                'authorization_json' => $completed['result']['authorization'] ?? null,
                'failure_code' => $position === $failure->failedPosition ? $failure->reasonCode : null,
                'failure_message' => $position === $failure->failedPosition ? $failure->getMessage() : null,
                'started_at' => $position <= $failure->failedPosition ? now() : null,
                'completed_at' => now(),
                'duration_ms' => $completed['duration_ms'] ?? null,
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function persistDelivery(
        TicketRuleRun $run,
        Ticket $ticket,
        TicketRuleActionResult $actionResult,
        array $payload,
    ): TicketRuleAfterCommitResult {
        $deliveryKey = TicketRuleStableJson::checksum([
            'action_result_id' => $actionResult->id,
            'delivery_type' => $payload['type'] ?? 'unknown',
        ]);

        return TicketRuleAfterCommitResult::query()->create([
            'run_id' => $run->id,
            'action_result_id' => $actionResult->id,
            'ticket_id' => $ticket->id,
            'delivery_key' => $deliveryKey,
            'attempt_number' => 1,
            'retry_of_id' => null,
            'precondition_fingerprint' => TicketRuleStableJson::checksum($payload),
            'idempotency_key' => TicketRuleStableJson::checksum([$deliveryKey, 'attempt' => 1]),
            'delivery_type' => (string) ($payload['type'] ?? 'unknown'),
            'status' => TicketRuleAfterCommitResult::STATUS_QUEUED,
            'attempt_count' => 0,
            'safe_payload_json' => $this->sanitizer->map($payload),
            'queued_at' => now(),
        ]);
    }

    /**
     * Queue one canonical derived mutation only when a frozen published
     * definition can consume it. Runtime facts stay in memory and the durable
     * event stores only the sanitizer projection.
     *
     * @param  Collection<int, TicketRuleVersion>  $versions
     * @param  array<string, mixed>  $postActionFacts
     * @param  array<int, TicketRuleEventEnvelope>  $runtimeEnvelopes
     * @param  array<string, int>  $limits
     * @return array{blocked: bool, reason_code: string|null, blocked_event_fingerprint: string|null}|null
     */
    private function enqueueDerivedMutationEvent(
        Ticket $ticket,
        TicketRuleRun $run,
        TicketRuleEvent $parentEvent,
        TicketRuleEventEnvelope $parentEnvelope,
        TicketRuleActionResult $actionResult,
        TicketRuleMutationEvent $mutation,
        Collection $versions,
        array $postActionFacts,
        array &$runtimeEnvelopes,
        array $limits,
    ): ?array {
        if ((int) $ticket->id !== $mutation->ticketId) {
            throw new RuntimeException('A derived Ticket Rule event belongs to a different Ticket.');
        }

        $safeBefore = $this->sanitizer->map($mutation->before);
        $safeAfter = $this->sanitizer->map($mutation->after);
        $facts = array_merge(
            $parentEnvelope->facts,
            $postActionFacts,
            $mutation->safeFacts,
            $mutation->classification,
            $mutation->after,
            [
                'event_source_channel' => $mutation->sourceChannel,
                'event_source_action' => $mutation->sourceAction,
                'related_record_type' => $mutation->relatedRecordType,
                'related_record_id' => $mutation->relatedRecordId,
            ],
        );
        $fingerprint = TicketRuleDerivedEventIdentity::fingerprint(
            (int) $ticket->id,
            $mutation->eventKey,
            $mutation->changedFields,
            $safeBefore,
            $safeAfter,
            $mutation->safeFacts,
            $mutation->classification,
        );
        $chainDepth = $parentEvent->chain_depth + 1;
        $idempotencyKey = TicketRuleStableJson::checksum([
            'run_id' => $run->id,
            'parent_action_result_id' => $actionResult->id,
            'delivery_identity' => hash('sha256', $mutation->deliveryIdentity),
            'event_fingerprint' => $fingerprint,
        ]);
        $envelope = new TicketRuleEventEnvelope(
            ticketId: (int) $ticket->id,
            eventKey: $mutation->eventKey,
            sourceChannel: $mutation->sourceChannel,
            sourceAction: $mutation->sourceAction,
            changedFields: $mutation->changedFields,
            before: $safeBefore,
            after: $safeAfter,
            facts: $facts,
            initiatorType: 'system_actor',
            initiatorId: $parentEnvelope->automationActorId,
            automationActorId: $parentEnvelope->automationActorId,
            correlationUuid: $parentEnvelope->correlationUuid,
            causationUuid: $mutation->causationUuid ?? $parentEnvelope->correlationUuid,
            parentEventId: $parentEvent->id,
            parentActionResultId: $actionResult->id,
            chainDepth: $chainDepth,
            occurredAt: now()->toImmutable(),
            fingerprint: $fingerprint,
            idempotencyKey: $idempotencyKey,
        );

        $hasRelevantConsumer = $versions->contains(function (TicketRuleVersion $candidate) use ($envelope): bool {
            $definition = is_array($candidate->definition_json) ? $candidate->definition_json : [];

            return $this->definitionIsRelevant($candidate, $definition, $envelope);
        });
        if (! $hasRelevantConsumer) {
            return null;
        }

        $reasonCode = null;
        if ($chainDepth > $limits['max_depth']) {
            $reasonCode = TicketRuleEvent::LOOP_REASON_DEPTH_BUDGET_EXCEEDED;
        } elseif (TicketRuleEvent::query()
            ->where('run_id', $run->id)
            ->where('event_fingerprint', $fingerprint)
            ->exists()) {
            $reasonCode = TicketRuleEvent::LOOP_REASON_REPEATED_EVENT_FINGERPRINT;
        }
        $sequence = (int) TicketRuleEvent::query()->where('run_id', $run->id)->max('sequence') + 1;

        if ($reasonCode !== null) {
            TicketRuleEvent::query()->create(array_replace(
                $envelope->persistence(),
                [
                    'run_id' => $run->id,
                    'sequence' => $sequence,
                    'event_fingerprint' => TicketRuleStableJson::checksum([
                        'blocked_fingerprint' => $fingerprint,
                        'parent_action_result_id' => $actionResult->id,
                    ]),
                    'idempotency_key' => TicketRuleStableJson::checksum([
                        'blocked' => $idempotencyKey,
                        'parent_action_result_id' => $actionResult->id,
                    ]),
                    'loop_reason_code' => $reasonCode,
                    'blocked_event_fingerprint' => $fingerprint,
                    'status' => TicketRuleEvent::STATUS_LOOP_BLOCKED,
                    'processed_at' => now(),
                ],
            ));

            return [
                'blocked' => true,
                'reason_code' => $reasonCode,
                'blocked_event_fingerprint' => $fingerprint,
            ];
        }

        $event = TicketRuleEvent::query()->create($envelope->persistence() + [
            'run_id' => $run->id,
            'sequence' => $sequence,
            'status' => TicketRuleEvent::STATUS_QUEUED,
        ]);
        $runtimeEnvelopes[$event->id] = $envelope;

        return [
            'blocked' => false,
            'reason_code' => null,
            'blocked_event_fingerprint' => null,
        ];
    }

    /**
     * @param  array<string, array{before: mixed, after: mixed}>  $changes
     * @param  array<int, TicketRuleEventEnvelope>  $runtimeEnvelopes
     * @param  array<string, int>  $limits
     * @return array{blocked: bool, reason_code: string|null, blocked_event_fingerprint: string|null}|null
     */
    private function enqueueDerivedEvent(
        Ticket $ticket,
        TicketRuleRun $run,
        TicketRuleEvent $parentEvent,
        TicketRuleEventEnvelope $parentEnvelope,
        TicketRuleActionResult $actionResult,
        string $actionType,
        array $changes,
        Collection $versions,
        array $postActionFacts,
        array &$runtimeEnvelopes,
        array $limits,
    ): ?array {
        $changedFields = array_values(array_keys($changes));
        $before = collect($changes)->mapWithKeys(fn (array $change, string $field): array => [
            $field => $change['before'] ?? null,
        ])->all();
        $after = collect($changes)->mapWithKeys(fn (array $change, string $field): array => [
            $field => $change['after'] ?? null,
        ])->all();
        $eventKey = in_array('tag_ids', $changedFields, true)
            ? self::EVENT_TAGS_CHANGED
            : self::EVENT_FIELDS_CHANGED;
        $hasRelevantConsumer = $versions->contains(function (TicketRuleVersion $candidate) use ($eventKey): bool {
            $definition = is_array($candidate->definition_json) ? $candidate->definition_json : [];

            return ($definition['trigger'] ?? null) === $eventKey;
        });
        if (! $hasRelevantConsumer) {
            return null;
        }

        $fingerprint = TicketRuleDerivedEventIdentity::fingerprint(
            (int) $ticket->id,
            $eventKey,
            $changedFields,
            $before,
            $after,
        );
        $chainDepth = $parentEvent->chain_depth + 1;
        $reasonCode = null;
        if ($chainDepth > $limits['max_depth']) {
            $reasonCode = TicketRuleEvent::LOOP_REASON_DEPTH_BUDGET_EXCEEDED;
        } elseif (TicketRuleEvent::query()
            ->where('run_id', $run->id)
            ->where('event_fingerprint', $fingerprint)
            ->exists()) {
            $reasonCode = TicketRuleEvent::LOOP_REASON_REPEATED_EVENT_FINGERPRINT;
        }
        $sequence = (int) TicketRuleEvent::query()->where('run_id', $run->id)->max('sequence') + 1;

        if ($reasonCode !== null) {
            TicketRuleEvent::query()->create([
                'run_id' => $run->id,
                'ticket_id' => $ticket->id,
                'parent_event_id' => $parentEvent->id,
                'parent_action_result_id' => $actionResult->id,
                'sequence' => $sequence,
                'event_key' => $eventKey,
                'event_fingerprint' => TicketRuleStableJson::checksum([
                    'blocked_fingerprint' => $fingerprint,
                    'parent_action_result_id' => $actionResult->id,
                ]),
                'idempotency_key' => TicketRuleStableJson::checksum([
                    'blocked' => $fingerprint,
                    'parent_action_result_id' => $actionResult->id,
                ]),
                'source_channel' => 'automation',
                'source_action' => 'TicketRuleAction:'.$actionType,
                'changed_fields_json' => $changedFields,
                'before_json' => $before,
                'after_json' => $after,
                'initiator_type' => 'system_actor',
                'initiator_id' => $parentEnvelope->automationActorId,
                'automation_actor_id' => $parentEnvelope->automationActorId,
                'correlation_uuid' => $parentEnvelope->correlationUuid,
                'causation_uuid' => $parentEnvelope->correlationUuid,
                'chain_depth' => $chainDepth,
                'loop_reason_code' => $reasonCode,
                'blocked_event_fingerprint' => $fingerprint,
                'status' => TicketRuleEvent::STATUS_LOOP_BLOCKED,
                'occurred_at' => now(),
                'processed_at' => now(),
            ]);

            return [
                'blocked' => true,
                'reason_code' => $reasonCode,
                'blocked_event_fingerprint' => $fingerprint,
            ];
        }

        $idempotencyKey = TicketRuleStableJson::checksum([
            'run_id' => $run->id,
            'parent_action_result_id' => $actionResult->id,
            'event_fingerprint' => $fingerprint,
        ]);
        $facts = array_merge($parentEnvelope->facts, $postActionFacts, [
            'changed_fields' => $changedFields,
        ]);
        $envelope = new TicketRuleEventEnvelope(
            ticketId: (int) $ticket->id,
            eventKey: $eventKey,
            sourceChannel: 'automation',
            sourceAction: 'TicketRuleAction:'.$actionType,
            changedFields: $changedFields,
            before: $before,
            after: $after,
            facts: $facts,
            initiatorType: 'system_actor',
            initiatorId: $parentEnvelope->automationActorId,
            automationActorId: $parentEnvelope->automationActorId,
            correlationUuid: $parentEnvelope->correlationUuid,
            causationUuid: $parentEnvelope->correlationUuid,
            parentEventId: $parentEvent->id,
            parentActionResultId: $actionResult->id,
            chainDepth: $chainDepth,
            occurredAt: now()->toImmutable(),
            fingerprint: $fingerprint,
            idempotencyKey: $idempotencyKey,
        );
        $event = TicketRuleEvent::query()->create($envelope->persistence() + [
            'run_id' => $run->id,
            'sequence' => $sequence,
            'status' => TicketRuleEvent::STATUS_QUEUED,
        ]);
        $runtimeEnvelopes[$event->id] = $envelope;

        return [
            'blocked' => false,
            'reason_code' => null,
            'blocked_event_fingerprint' => null,
        ];
    }

    /**
     * Reject a corrupt frozen catalogue before creating any runtime evidence.
     *
     * @param  Collection<int, TicketRuleVersion>  $versions
     */
    private function assertFrozenDefinitions(Collection $versions): void
    {
        foreach ($versions as $version) {
            $definition = is_array($version->definition_json) ? $version->definition_json : [];
            $schemaVersion = (int) $version->definition_schema_version;

            if ($schemaVersion === TicketRuleDefinitionRegistry::LEGACY_COMPATIBILITY_SCHEMA_VERSION
                && ($definition['trigger'] ?? null) !== TicketRuleDefinitionRegistry::TRIGGER_CREATED) {
                throw new RuntimeException(
                    'A published Ticket Rule trigger is outside Slice 2 runtime authority.',
                );
            }

            $validation = $this->definitionValidator->validateStored($definition);
            if (($validation['status'] ?? null) !== TicketRulePublishedDefinitionValidator::STATUS_VALID
                || $schemaVersion !== (int) ($validation['schema_version'] ?? 0)
                || TicketRuleStableJson::checksum($definition) !== $version->definition_checksum
                || ($validation['checksum'] ?? null) !== $version->definition_checksum) {
                throw new RuntimeException(
                    'The frozen published Ticket Rule catalogue contains invalid definition evidence.',
                );
            }
        }
    }

    /** @param array<string, mixed> $action @return array<string, mixed> */
    private function actionSnapshot(TicketRuleVersion $version, array $action): array
    {
        return (int) $version->definition_schema_version
            === TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION
                ? $this->schema2ActionExecutor->snapshot($action)
                : $this->compatibilityActionExecutor->snapshot($action);
    }

    /**
     * Relevance is resolved before condition evaluation and before consuming
     * the evaluated-rule budget. Invalid definitions that target this event
     * remain candidates so immutable corruption is recorded as a failure.
     *
     * @param  array<string, mixed>  $definition
     */
    private function definitionIsRelevant(
        TicketRuleVersion $version,
        array $definition,
        TicketRuleEventEnvelope $envelope,
    ): bool {
        $schemaVersion = (int) $version->definition_schema_version;
        $trigger = $definition['trigger'] ?? null;
        $eventKeys = collect((array) ($envelope->facts['event_keys'] ?? []))
            ->prepend($envelope->eventKey)
            ->filter(fn (mixed $key): bool => is_string($key) && str_starts_with($key, 'ticket.'))
            ->unique()
            ->values()
            ->all();

        if ($schemaVersion === TicketRuleDefinitionRegistry::LEGACY_COMPATIBILITY_SCHEMA_VERSION) {
            return is_string($trigger) && in_array($trigger, $eventKeys, true);
        }

        if ($schemaVersion !== TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION
            || ! is_string($trigger)) {
            return is_string($trigger) && in_array($trigger, $eventKeys, true);
        }

        $triggerDefinition = $this->triggerRegistry->definition($trigger);
        $acceptedEventKeys = (array) ($triggerDefinition['event_keys'] ?? []);
        $candidateEventKeys = array_values(array_intersect($eventKeys, $acceptedEventKeys));
        if ($triggerDefinition === null
            || $candidateEventKeys === []
            || ! $this->triggerRegistry->enabled($trigger)) {
            return false;
        }

        $filters = $this->triggerRegistry->canonicalizeFilters(
            $trigger,
            $definition['trigger_filters'] ?? null,
        );
        if (! ($filters['valid'] ?? false)) {
            return true;
        }

        $event = [
            'source_channel' => $envelope->sourceChannel,
            'changed_fields' => $envelope->changedFields,
            'before' => $envelope->before,
            'after' => $envelope->after,
            'facts' => $envelope->facts,
        ];

        foreach ($candidateEventKeys as $eventKey) {
            if ($this->triggerRegistry->isRelevant(
                $trigger,
                $filters['filters'],
                $event + ['event_key' => $eventKey],
            )) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function summaryDetails(TicketRuleRun $run): array
    {
        $changedRules = TicketRuleActionResult::query()
            ->where('run_id', $run->id)
            ->whereIn('status', [
                TicketRuleActionResult::STATUS_SUCCEEDED,
                TicketRuleActionResult::STATUS_QUEUED,
            ])
            ->get(['ticket_rule_id', 'rule_version_id', 'change_json'])
            ->map(fn (TicketRuleActionResult $result): array => [
                'ticket_rule_id' => (int) $result->ticket_rule_id,
                'rule_version_id' => (int) $result->rule_version_id,
                'changed_fields' => array_values(array_keys((array) $result->change_json)),
            ])
            ->filter(fn (array $item): bool => $item['changed_fields'] !== [])
            ->groupBy(fn (array $item): string => $item['ticket_rule_id'].':'.$item['rule_version_id'])
            ->map(fn (Collection $items): array => [
                'ticket_rule_id' => $items->first()['ticket_rule_id'],
                'rule_version_id' => $items->first()['rule_version_id'],
                'changed_fields' => $items->pluck('changed_fields')->flatten()->unique()->sort()->values()->all(),
            ])
            ->take(50)
            ->values()
            ->all();
        $failures = TicketRuleExecution::query()
            ->where('run_id', $run->id)
            ->whereIn('status', [
                TicketRuleExecution::STATUS_FAILED,
                TicketRuleExecution::STATUS_LOOP_BLOCKED,
            ])
            ->orderBy('id')
            ->limit(50)
            ->get(['ticket_rule_id', 'rule_version_id', 'failure_code', 'status'])
            ->map(fn (TicketRuleExecution $execution): array => [
                'ticket_rule_id' => (int) $execution->ticket_rule_id,
                'rule_version_id' => (int) $execution->rule_version_id,
                'status' => (string) $execution->status,
                'failure_code' => $execution->failure_code,
            ])
            ->all();

        $loopBlocks = TicketRuleEvent::query()
            ->where('run_id', $run->id)
            ->where('status', TicketRuleEvent::STATUS_LOOP_BLOCKED)
            ->whereNotNull('loop_reason_code')
            ->orderBy('sequence')
            ->limit(50)
            ->get([
                'sequence',
                'event_key',
                'loop_reason_code',
                'blocked_event_fingerprint',
            ])
            ->map(fn (TicketRuleEvent $event): array => [
                'sequence' => (int) $event->sequence,
                'event_key' => (string) $event->event_key,
                'reason_code' => (string) $event->loop_reason_code,
                'blocked_event_fingerprint' => $event->blocked_event_fingerprint,
            ])
            ->all();

        $decisions = TicketRuleActionResult::query()
            ->where('run_id', $run->id)
            ->whereIn('status', [
                TicketRuleActionResult::STATUS_SUCCEEDED,
                TicketRuleActionResult::STATUS_NO_CHANGE,
                TicketRuleActionResult::STATUS_QUEUED,
            ])
            ->get(['authorization_json'])
            ->map(fn (TicketRuleActionResult $result): array => (array) $result->authorization_json);

        return [
            'changed_rules' => $changedRules,
            'failures' => $failures,
            'loop_blocks' => $loopBlocks,
            'assignment_decision' => $decisions->contains(
                fn (array $decision): bool => ($decision['assignment_decision'] ?? false) === true,
            ),
            'sla_decision' => $decisions->contains(
                fn (array $decision): bool => ($decision['sla_decision'] ?? false) === true,
            ),
        ];
    }

    private function completeExecution(
        TicketRuleExecution $execution,
        mixed $startedAt,
        array $attributes,
    ): void {
        $completedAt = now();
        $execution->forceFill($attributes + [
            'completed_at' => $completedAt,
            'duration_ms' => max(0, (int) $startedAt->diffInMilliseconds($completedAt)),
        ])->save();
    }

    /** @return array<string, int> */
    private function limits(): array
    {
        return [
            'max_depth' => min(32, max(1, (int) config('ticket_rules.limits.max_depth', 8))),
            'max_evaluated_rules' => min(500, max(1, (int) config('ticket_rules.limits.max_evaluated_rules', 100))),
            'max_actions' => min(500, max(1, (int) config('ticket_rules.limits.max_actions', 100))),
        ];
    }
}
