<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleExecutionResult;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleRestrictedEvidence;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * A deliberately separate, warned full-run replay boundary.
 *
 * The signed receipt proves the operator previewed the exact current Ticket
 * state and published set. It is not a reusable authorization grant.
 */
final class TicketRuleFullRerunBoundary
{
    private const RECEIPT_TTL_SECONDS = 300;

    public function __construct(
        private readonly TicketRuleRuntimeGate $runtimeGate,
        private readonly TicketRulePreviewService $previewService,
        private readonly TicketRuleTicketState $ticketState,
        private readonly TicketRuleExecutionCoordinator $coordinator,
        private readonly TicketRuleFullRerunPreviewPresenter $previewPresenter,
        private readonly TicketRuleEvidenceAccess $evidenceAccess,
    ) {}

    /** @return array<string, mixed> */
    public function preview(TicketRuleRun $requestedRun, User $requestedOperator): array
    {
        $this->assertAvailable();
        $operator = $this->authorizedOperator($requestedOperator);
        $run = $this->authorizedSource($requestedRun);
        $ticket = $run->ticket;
        $preview = $this->createdPreview($ticket, $operator);
        $this->assertExecutablePreview($preview);
        $this->evidenceAccess->assertFullRerunAccess($preview, $operator);
        $expiresAt = now()->addSeconds(self::RECEIPT_TTL_SECONDS)->getTimestamp();
        $payload = [
            'version' => 1,
            'source_run_id' => (int) $run->id,
            'ticket_id' => (int) $ticket->id,
            'work_context_id' => (int) $ticket->work_context_id,
            'operator_id' => (int) $operator->id,
            'ticket_state_fingerprint' => $this->ticketState->fingerprint($ticket),
            'authority_generation' => (int) $preview['authority_generation'],
            'authority_checksum' => (string) $preview['authority_checksum'],
            'published_set_checksum' => (string) $preview['published_set_checksum'],
            'preview_plan_checksum' => TicketRuleStableJson::checksum($preview),
            'expires_at' => $expiresAt,
            'nonce' => (string) Str::uuid(),
        ];

        return [
            'warning' => 'Full rerun evaluates the complete current published rule set and may repeat Ticket changes, internal notes, signals, or queued external deliveries. Review every planned action before confirming.',
            'receipt' => $this->sign($payload),
            'expires_at' => $expiresAt,
            'source_run_id' => (int) $run->id,
            'ticket_id' => (int) $ticket->id,
            'terminal_status' => (string) ($preview['terminal_status'] ?? 'unknown'),
            'planned_rule_count' => count((array) ($preview['rules'] ?? []))
                + max(0, (int) ($preview['rules_omitted_count'] ?? 0)),
            'planned_action_count' => (int) data_get($preview, 'counters.actions', 0),
            'collision_count' => count((array) ($preview['collisions'] ?? []))
                + max(0, (int) ($preview['collisions_omitted_count'] ?? 0)),
            'loop_block_count' => (int) data_get($preview, 'counters.loop_blocks', 0),
            'loop_risk_status' => (string) data_get($preview, 'loop_risk.status', 'unknown'),
            'halted' => (bool) ($preview['halted'] ?? false),
        ] + $this->previewPresenter->present($preview);
    }

    public function execute(
        TicketRuleRun $requestedRun,
        User $requestedOperator,
        string $receipt,
    ): TicketRuleExecutionResult {
        $this->assertAvailable();
        $operator = $this->authorizedOperator($requestedOperator);
        $payload = $this->verify($receipt);

        if ((int) ($payload['source_run_id'] ?? 0) !== (int) $requestedRun->id
            || (int) ($payload['operator_id'] ?? 0) !== (int) $operator->id) {
            throw new RuntimeException('The full-rerun preview receipt belongs to a different request.');
        }

        return DB::transaction(function () use (
            $requestedRun,
            $operator,
            $payload,
            $receipt,
        ): TicketRuleExecutionResult {
            $run = $this->authorizedSource($requestedRun, true);
            $existing = $this->existingLinkedRun($run, $receipt, $operator);
            if ($existing !== null) {
                return $existing;
            }

            $this->assertReceiptIsFresh($payload);

            $ticket = Ticket::query()
                ->whereKey($run->ticket_id)
                ->where('work_context_id', (int) ($payload['work_context_id'] ?? 0))
                ->whereHas('workContext', fn ($query) => $query->whereKey((int) ($payload['work_context_id'] ?? 0)))
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) ($payload['ticket_id'] ?? 0) !== (int) $ticket->id
                || ! hash_equals(
                    (string) ($payload['ticket_state_fingerprint'] ?? ''),
                    $this->ticketState->fingerprint($ticket),
                )) {
                throw new RuntimeException('The Ticket changed after preview. Generate a new full-rerun preview.');
            }

            $currentPreview = $this->createdPreview($ticket, $operator);
            $this->assertExecutablePreview($currentPreview);
            $this->evidenceAccess->assertFullRerunAccess($currentPreview, $operator);
            $currentEvidence = [
                'authority_generation' => (string) ($currentPreview['authority_generation'] ?? ''),
                'authority_checksum' => (string) ($currentPreview['authority_checksum'] ?? ''),
                'published_set_checksum' => (string) ($currentPreview['published_set_checksum'] ?? ''),
                'preview_plan_checksum' => TicketRuleStableJson::checksum($currentPreview),
            ];
            foreach ($currentEvidence as $field => $currentValue) {
                if ((string) ($payload[$field] ?? '') !== $currentValue) {
                    throw new RuntimeException(
                        'The Ticket Rule authority or no-write plan changed after preview. Generate a new full-rerun preview.',
                    );
                }
            }

            $rootEvent = $run->events()->orderBy('sequence')->firstOrFail();
            $facts = $this->ticketState->facts($ticket);
            $event = TicketRuleMutationEvent::make(
                ticketId: (int) $ticket->id,
                eventKey: TicketRuleDefinitionRegistry::TRIGGER_CREATED,
                changedFields: ['created'],
                before: [],
                after: $facts,
                safeFacts: $facts,
                classification: [
                    'event_keys' => [TicketRuleDefinitionRegistry::TRIGGER_CREATED],
                    'full_rerun' => true,
                    'source_run_id' => (int) $run->id,
                    'source_event_id' => (int) $rootEvent->id,
                ],
                sourceChannel: 'ticket_rule_full_rerun',
                sourceAction: 'TicketRuleFullRerunBoundary.execute',
                deliveryIdentity: $this->deliveryIdentity($receipt),
                correlationUuid: (string) Str::uuid(),
                causationUuid: (string) $run->correlation_uuid,
            );

            return $this->coordinator->executeFullRerun($ticket, $event, $operator, $run);
        }, 3);
    }

    public function availableFor(TicketRuleRun $run, ?User $operator): bool
    {
        try {
            $this->assertAvailable();
            $this->authorizedOperator($operator);
            $this->authorizedSource($run);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @param array<string, mixed> $preview */
    private function assertExecutablePreview(array $preview): void
    {
        $scope = array_values((array) ($preview['execution_scope'] ?? []));
        $terminalStatus = (string) ($preview['terminal_status'] ?? '');

        if (($preview['mode'] ?? null) !== 'preview'
            || $scope !== [TicketRuleDefinitionRegistry::TRIGGER_CREATED]
            || (bool) ($preview['halted'] ?? true)
            || in_array($terminalStatus, ['failed', 'loop_blocked', ''], true)) {
            throw new RuntimeException(
                'Full rerun is unavailable because the no-write preview did not produce a complete executable Ticket-created plan.',
            );
        }
    }

    private function assertAvailable(): void
    {
        if (! (bool) config('ticket_rules.full_rerun_enabled', false)
            || ! $this->runtimeGate->enabled()) {
            throw new RuntimeException('Ticket Rule full rerun is disabled until v2 authority and its reviewed capability are active.');
        }
    }

    private function authorizedOperator(?User $requested): User
    {
        $operator = $requested?->id
            ? User::query()->whereKey($requested->id)->first()
            : null;

        if (! $operator
            || ! $operator->isActive()
            || ! $operator->can('ticket.view')
            || ! $operator->can('ticket.rule_preview')
            || ! $operator->can('ticket.rule_full_rerun')) {
            throw new RuntimeException('Ticket view, Ticket Rule preview, and Ticket Rule full-rerun permissions are required.');
        }

        return $operator;
    }

    private function authorizedSource(TicketRuleRun $requested, bool $lock = false): TicketRuleRun
    {
        $query = TicketRuleRun::query()
            ->with('ticket:id,ticket_key,work_context_id')
            ->whereKey($requested->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        $run = $query->firstOrFail();
        if (! $run->ticket
            || $run->root_event_key !== TicketRuleDefinitionRegistry::TRIGGER_CREATED
            || ! in_array($run->status, [
                TicketRuleRun::STATUS_SUCCEEDED,
                TicketRuleRun::STATUS_FAILED,
                TicketRuleRun::STATUS_NO_CHANGE,
                TicketRuleRun::STATUS_LOOP_BLOCKED,
            ], true)) {
            throw new RuntimeException('This execution is not eligible for full rerun.');
        }

        return $run;
    }

    /**
     * A submitted receipt owns one immutable run identity. A browser retry
     * therefore returns the terminal linked run even when the first execution
     * changed the Ticket and invalidated the preview state fingerprint.
     */
    private function existingLinkedRun(
        TicketRuleRun $source,
        string $receipt,
        User $operator,
    ): ?TicketRuleExecutionResult {
        $rootIdempotencyKey = TicketRuleStableJson::checksum([
            'ticket_id' => (int) $source->ticket_id,
            'event_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'delivery_identity' => hash('sha256', $this->deliveryIdentity($receipt)),
        ]);
        $existing = TicketRuleRun::query()
            ->where('root_idempotency_key', $rootIdempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            return null;
        }

        if ((int) $existing->ticket_id !== (int) $source->ticket_id
            || $existing->mode !== 'full_rerun'
            || (int) $existing->retry_of_run_id !== (int) $source->id) {
            throw new RuntimeException('The full-rerun receipt identity is owned by another execution.');
        }

        $existing->load([
            'executions' => fn ($query) => $query->with('version'),
        ]);
        if ($this->evidenceAccess->runIsRestricted($existing, $operator)) {
            throw new RuntimeException('The linked full-rerun evidence is restricted.');
        }

        if ($existing->status === TicketRuleRun::STATUS_RUNNING) {
            throw new RuntimeException('The linked full rerun is still incomplete.');
        }

        return new TicketRuleExecutionResult(
            $existing,
            $existing->status,
            [],
            (array) $existing->counters_json,
            (array) $existing->safe_summary_json,
        );
    }

    private function deliveryIdentity(string $receipt): string
    {
        return 'ticket-rule-full-rerun:'.hash('sha256', $receipt);
    }

    /** @return array<string, mixed> */
    private function createdContext(Ticket $ticket): array
    {
        return [
            'channel' => $ticket->channel,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            '_source_action' => 'TicketRuleFullRerunBoundary.plan',
        ];
    }

    /**
     * The shared queue preview protects Custom Field evidence with its own
     * generic denial. Translate that denial at this boundary so full-rerun
     * operators receive one stable, non-inferential error contract.
     *
     * @return array<string, mixed>
     */
    private function createdPreview(Ticket $ticket, User $operator): array
    {
        try {
            return $this->previewService->created($ticket, $this->createdContext($ticket), $operator);
        } catch (TicketRuleRestrictedEvidence) {
            $this->evidenceAccess->denyFullRerun();
        }
    }

    /** @param array<string, mixed> $payload */
    private function sign(array $payload): string
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('The full-rerun preview receipt could not be created.', previous: $exception);
        }

        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, $this->signingKey());
    }

    /** @return array<string, mixed> */
    private function verify(string $receipt): array
    {
        [$encoded, $signature] = array_pad(explode('.', trim($receipt), 2), 2, null);
        if (! is_string($encoded)
            || ! is_string($signature)
            || ! hash_equals(hash_hmac('sha256', $encoded, $this->signingKey()), $signature)) {
            throw new RuntimeException('The full-rerun preview receipt is invalid.');
        }

        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);

        try {
            $payload = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $exception) {
            throw new RuntimeException('The full-rerun preview receipt is invalid.', previous: $exception);
        }

        if (! is_array($payload) || (int) ($payload['version'] ?? 0) !== 1) {
            throw new RuntimeException('The full-rerun preview receipt is invalid.');
        }

        return $payload;
    }

    /**
     * Expiry prevents a new execution, but a valid duplicate receipt may still
     * resolve the immutable run it already created.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertReceiptIsFresh(array $payload): void
    {
        if ((int) ($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
            throw new RuntimeException('The full-rerun preview receipt expired.');
        }
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Application signing key is unavailable.');
        }

        return hash('sha256', 'ticket-rule-full-rerun|'.$key, true);
    }
}
