<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Models\Signal;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRuleAfterCommitResult;
use App\Modules\Ticket\Services\TicketRuleAuditSanitizer;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class DispatchTicketRuleAfterCommit
{
    public function __construct(
        private readonly RecordSignal $recordSignal,
        private readonly TicketRuleAuditSanitizer $sanitizer,
    ) {}

    /** @param array<string, mixed> $payload */
    public function signal(int $deliveryId, array $payload): void
    {
        $delivery = $this->claim($deliveryId);

        if (! $delivery) {
            return;
        }

        try {
            $ticket = Ticket::query()->with(['tags', 'contact'])->findOrFail($delivery->ticket_id);
            $existing = Signal::query()
                ->where('source_domain', 'ticket')
                ->where('source_type', $ticket->getMorphClass())
                ->where('source_id', $ticket->id)
                ->where('payload->ticket_rule_delivery_key', $delivery->delivery_key)
                ->first();

            $signal = $existing ?: $this->recordSignal->handle([
                'source_domain' => 'ticket',
                'source_type' => $ticket->getMorphClass(),
                'source_id' => $ticket->id,
                'contact_id' => $ticket->contact?->contact_id,
                'client_id' => $ticket->client_id,
                'signal_type' => $payload['signal_type'],
                'severity' => $payload['severity'] ?? 'info',
                'confidence' => $payload['confidence'] ?? 100,
                'summary' => $payload['summary']
                    ?? 'Ticket rule signal: '.str_replace('_', ' ', (string) $payload['signal_type']),
                'payload' => [
                    'ticket_id' => $ticket->id,
                    'ticket_key' => $ticket->ticket_key,
                    'ticket_rule_id' => $payload['ticket_rule_id'] ?? null,
                    'ticket_rule_name' => $payload['ticket_rule_name'] ?? null,
                    'ticket_rule_action_index' => $payload['ticket_rule_action_index'] ?? null,
                    'ticket_rule_delivery_key' => $delivery->delivery_key,
                    'channel' => $ticket->channel,
                    'queue_id' => $ticket->queue_id,
                    'ticket_type_id' => $ticket->ticket_type_id,
                    'priority_id' => $ticket->priority_id,
                    'category_id' => $ticket->category_id,
                    'sla_id' => $ticket->sla_id,
                    'sla_source' => $ticket->sla_source,
                    'tags' => $ticket->tags->pluck('name')->values()->all(),
                    'note' => $payload['payload_note'] ?? null,
                ],
                'occurred_at' => $ticket->created_at ?: now(),
            ]);

            TicketRuleAfterCommitResult::query()
                ->whereKey($delivery->id)
                ->where('status', TicketRuleAfterCommitResult::STATUS_RUNNING)
                ->update([
                    'status' => TicketRuleAfterCommitResult::STATUS_SUCCEEDED,
                    'external_reference_fingerprint' => hash('sha256', 'signal:'.$signal->id),
                    'completed_at' => now(),
                ]);
        } catch (Throwable $exception) {
            TicketRuleAfterCommitResult::query()
                ->whereKey($delivery->id)
                ->where('status', TicketRuleAfterCommitResult::STATUS_RUNNING)
                ->update([
                    'status' => TicketRuleAfterCommitResult::STATUS_FAILED,
                    'failure_code' => 'signal_dispatch_failed',
                    'failure_message' => $this->sanitizer->message($exception->getMessage()),
                    'completed_at' => now(),
                ]);
        }
    }

    public function markStaleRunningUnresolved(int $deliveryId, DateTimeInterface $staleBefore): bool
    {
        return DB::transaction(function () use ($deliveryId, $staleBefore): bool {
            $delivery = TicketRuleAfterCommitResult::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if (! $delivery
                || $delivery->status !== TicketRuleAfterCommitResult::STATUS_RUNNING
                || $delivery->started_at === null
                || $delivery->started_at->greaterThan($staleBefore)) {
                return false;
            }

            $delivery->forceFill([
                'status' => TicketRuleAfterCommitResult::STATUS_UNRESOLVED,
                'failure_code' => 'worker_outcome_unknown',
                'failure_message' => 'The worker outcome is unknown; retry requires explicit reconciliation.',
                'completed_at' => now(),
            ])->save();

            return true;
        }, 3);
    }

    public function retryUnresolved(
        int $deliveryId,
        string $confirmedNotDeliveredReference,
    ): ?TicketRuleAfterCommitResult {
        $reference = trim($confirmedNotDeliveredReference);
        if ($reference === '' || mb_strlen($reference) > 512) {
            throw new InvalidArgumentException('Confirmed-not-delivered reconciliation evidence is required.');
        }

        $reconciliationFingerprint = hash('sha256', 'confirmed-not-delivered:'.$reference);

        [$retry, $payload] = DB::transaction(
            function () use ($deliveryId, $reconciliationFingerprint): array {
                $original = TicketRuleAfterCommitResult::query()
                    ->whereKey($deliveryId)
                    ->lockForUpdate()
                    ->first();

                if (! $original || $original->status !== TicketRuleAfterCommitResult::STATUS_UNRESOLVED) {
                    return [null, null];
                }

                $payload = $this->retrySignalPayload($original);
                $attemptNumber = $original->attempt_number + 1;
                $existing = TicketRuleAfterCommitResult::query()
                    ->where('retry_of_id', $original->id)
                    ->where('attempt_number', $attemptNumber)
                    ->first();

                if ($existing) {
                    if (! hash_equals((string) $existing->reconciliation_fingerprint, $reconciliationFingerprint)) {
                        throw new InvalidArgumentException('Retry reconciliation evidence does not match the existing attempt.');
                    }

                    return [$existing, $payload];
                }

                $retry = TicketRuleAfterCommitResult::query()->create([
                    'run_id' => $original->run_id,
                    'action_result_id' => $original->action_result_id,
                    'ticket_id' => $original->ticket_id,
                    'delivery_key' => $original->delivery_key,
                    'attempt_number' => $attemptNumber,
                    'retry_of_id' => $original->id,
                    'precondition_fingerprint' => $original->precondition_fingerprint,
                    'idempotency_key' => hash('sha256', $original->idempotency_key.'|retry|'.$attemptNumber),
                    'delivery_type' => $original->delivery_type,
                    'status' => TicketRuleAfterCommitResult::STATUS_QUEUED,
                    'attempt_count' => 0,
                    'safe_payload_json' => $original->safe_payload_json,
                    'reconciliation_fingerprint' => $reconciliationFingerprint,
                    'queued_at' => now(),
                ]);

                return [$retry, $payload];
            },
            3,
        );

        if (! $retry || ! is_array($payload)) {
            return null;
        }

        // Raw free text is rebuilt from the immutable published action and lives
        // only in this after-commit callback; safe_payload_json is never replayed.
        DB::afterCommit(function () use ($retry, $payload): void {
            $this->signal($retry->id, $payload);
        });

        return $retry;
    }

    /** @return array<string, mixed> */
    private function retrySignalPayload(TicketRuleAfterCommitResult $delivery): array
    {
        if ($delivery->delivery_type !== 'emit_signal') {
            throw new InvalidArgumentException('Only emit_signal delivery retries are supported.');
        }

        $actionResult = $delivery->actionResult()->with('version.rule')->first();
        $version = $actionResult?->version;
        $definition = is_array($version?->definition_json) ? $version->definition_json : [];

        if (! $actionResult
            || ! $version
            || ! hash_equals((string) $version->definition_checksum, TicketRuleStableJson::checksum($definition))) {
            throw new InvalidArgumentException('The immutable retry action evidence is invalid.');
        }

        $actionListKey = match ($actionResult->branch) {
            'then' => 'then_actions',
            'else' => 'else_actions',
            default => throw new InvalidArgumentException('The retry action branch is invalid.'),
        };
        $actions = (array) ($definition[$actionListKey] ?? []);
        $action = $actions[$actionResult->position] ?? null;

        if (! is_array($action)
            || ($action['type'] ?? null) !== 'emit_signal'
            || ! hash_equals(
                TicketRuleStableJson::checksum((array) $actionResult->action_snapshot_json),
                TicketRuleStableJson::checksum($this->sanitizer->map($action)),
            )) {
            throw new InvalidArgumentException('The immutable retry action position is invalid.');
        }

        $signalType = str((string) ($action['signal_type'] ?? $action['value'] ?? ''))
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();

        if ($signalType === '') {
            throw new InvalidArgumentException('The immutable retry signal type is invalid.');
        }

        return [
            'type' => 'emit_signal',
            'signal_type' => $signalType,
            'severity' => $action['severity'] ?? 'info',
            'confidence' => max(0, min(100, (int) ($action['confidence'] ?? 100))),
            'summary' => $action['summary'] ?? null,
            'payload_note' => $action['payload_note'] ?? null,
            'ticket_rule_id' => (int) $actionResult->ticket_rule_id,
            'ticket_rule_name' => (string) $version->rule?->name,
            'ticket_rule_action_index' => (int) $actionResult->position,
        ];
    }

    private function claim(int $deliveryId): ?TicketRuleAfterCommitResult
    {
        return DB::transaction(function () use ($deliveryId): ?TicketRuleAfterCommitResult {
            $delivery = TicketRuleAfterCommitResult::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if (! $delivery || $delivery->status !== TicketRuleAfterCommitResult::STATUS_QUEUED) {
                return null;
            }

            $delivery->forceFill([
                'status' => TicketRuleAfterCommitResult::STATUS_RUNNING,
                'attempt_count' => $delivery->attempt_count + 1,
                'started_at' => now(),
            ])->save();

            return $delivery->refresh();
        }, 3);
    }
}
