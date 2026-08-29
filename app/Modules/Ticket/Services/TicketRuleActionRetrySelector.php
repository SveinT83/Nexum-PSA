<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class TicketRuleActionRetrySelector
{
    /** @var list<string> */
    private const IDEMPOTENT_ACTION_TYPES = [
        'set_ticket_type',
        'set_queue',
        'set_priority',
        'set_sla',
        'set_category',
        'add_tag',
    ];

    public function __construct(
        private readonly TicketRuleTicketState $ticketState,
        private readonly TicketRuleCompatibilityTargetValidator $targetValidator,
        private readonly TicketRuleActionProviderRegistry $providers,
        private readonly TicketRuleRetryPolicy $retryPolicy,
    ) {}

    /** @return Collection<int, TicketRuleActionResult> */
    public function candidates(Ticket $ticket, ?int $runId = null): Collection
    {
        $rankedByPosition = TicketRuleActionResult::query()
            ->where('ticket_id', $ticket->id)
            ->select(['id', 'position_key', 'attempt_number'])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY position_key ORDER BY attempt_number DESC, id DESC) AS attempt_rank',
            );
        if ($runId !== null) {
            $rankedByPosition->where('run_id', $runId);
        }
        $latestByPosition = DB::query()
            ->fromSub($rankedByPosition->toBase(), 'ranked_retry_attempts')
            ->select('id')
            ->where('attempt_rank', 1);

        $query = TicketRuleActionResult::query()
            ->whereIn('id', $latestByPosition)
            ->whereIn('status', [
                TicketRuleActionResult::STATUS_FAILED,
                TicketRuleActionResult::STATUS_NOT_RUN,
            ])
            ->where(
                'attempt_number',
                '<',
                $this->retryPolicy->maxAttemptsPerPosition(),
            )
            ->with(['version', 'execution'])
            ->orderBy('id')
            ->limit($this->retryPolicy->maxCandidatePositions());

        if ($runId !== null) {
            $query->where('run_id', $runId);
        }

        return $query
            ->get()
            ->filter(fn (TicketRuleActionResult $result): bool => $this->isEligible($result, $ticket))
            ->values();
    }

    public function isEligible(TicketRuleActionResult $result, Ticket $ticket): bool
    {
        if ((int) $result->ticket_id !== (int) $ticket->id
            || ! in_array($result->status, [
                TicketRuleActionResult::STATUS_FAILED,
                TicketRuleActionResult::STATUS_NOT_RUN,
            ], true)
            || (int) $result->attempt_number >= $this->retryPolicy->maxAttemptsPerPosition()
            || ! is_array($result->action_snapshot_json)
            || ! $this->actionIsRetryable($result)) {
            return false;
        }

        $currentPrecondition = $this->ticketState->fingerprint($ticket);
        if (! hash_equals((string) $result->precondition_fingerprint, $currentPrecondition)) {
            return false;
        }

        return ! TicketRuleActionResult::query()
            ->where('position_key', $result->position_key)
            ->where('id', '<>', $result->id)
            ->where(function ($query) use ($result): void {
                $query->whereIn('status', [
                    TicketRuleActionResult::STATUS_PLANNED,
                    TicketRuleActionResult::STATUS_SUCCEEDED,
                    TicketRuleActionResult::STATUS_NO_CHANGE,
                    TicketRuleActionResult::STATUS_QUEUED,
                ])
                    ->orWhere('attempt_number', '>', $result->attempt_number)
                    ->orWhere(function ($newer) use ($result): void {
                        $newer->where('attempt_number', $result->attempt_number)
                            ->where('id', '>', $result->id);
                    });
            })
            ->exists();
    }

    /**
     * Reconstruct the declarative action from the immutable version. Audit
     * snapshots are intentionally redacted and are never executable input.
     *
     * @return array<string, mixed>|null
     */
    public function sourceAction(TicketRuleActionResult $result): ?array
    {
        $result->loadMissing(['version', 'execution']);
        $version = $result->version;
        $execution = $result->execution;
        if (! $version || ! $execution || ! is_array($version->definition_json)) {
            return null;
        }

        $branch = $result->branch === 'else' ? 'else_actions' : 'then_actions';
        $actions = (array) ($version->definition_json[$branch] ?? []);
        $action = $actions[(int) $result->position] ?? null;

        return is_array($action) ? $action : null;
    }

    private function actionIsRetryable(TicketRuleActionResult $result): bool
    {
        $result->loadMissing('version');
        $version = $result->version;
        $action = $this->sourceAction($result);
        if (! $version || $action === null) {
            return false;
        }

        if ((int) $version->definition_schema_version
            !== TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
            return in_array($result->action_type, self::IDEMPOTENT_ACTION_TYPES, true)
                && $this->targetValidator->failureCode($result->action_snapshot_json) === null;
        }

        $canonical = $this->providers->canonicalizeAction($action);
        if (! ($canonical['valid'] ?? false)) {
            return false;
        }

        $type = (string) data_get($canonical, 'action.type', '');
        $provider = $this->providers->definition($type);

        return $type === (string) $result->action_type
            && ($provider['retryable'] ?? false) === true
            && ($provider['phase'] ?? null) === 'synchronous'
            && $this->providers->enabled($type);
    }

    public function reserveRetryAttempt(
        TicketRuleActionResult $result,
        Ticket $ticket,
    ): ?TicketRuleActionResult {
        return DB::transaction(function () use ($result, $ticket): ?TicketRuleActionResult {
            $lockedResult = TicketRuleActionResult::query()
                ->whereKey($result->id)
                ->lockForUpdate()
                ->first();
            $lockedTicket = Ticket::query()
                ->whereKey($ticket->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedResult || ! $lockedTicket) {
                return null;
            }

            $existing = TicketRuleActionResult::query()
                ->where('retry_of_id', $lockedResult->id)
                ->where('status', TicketRuleActionResult::STATUS_PLANNED)
                ->orderByDesc('attempt_number')
                ->first();

            if ($existing
                && hash_equals(
                    (string) $existing->precondition_fingerprint,
                    $this->ticketState->fingerprint($lockedTicket),
                )) {
                return $existing;
            }

            if (! $this->isEligible($lockedResult, $lockedTicket)) {
                return null;
            }

            $attemptNumber = (int) TicketRuleActionResult::query()
                ->where('position_key', $lockedResult->position_key)
                ->max('attempt_number') + 1;
            if ($attemptNumber > $this->retryPolicy->maxAttemptsPerPosition()) {
                return null;
            }
            $precondition = $this->ticketState->fingerprint($lockedTicket);

            return TicketRuleActionResult::query()->create([
                'run_id' => $lockedResult->run_id,
                'event_id' => $lockedResult->event_id,
                'execution_id' => $lockedResult->execution_id,
                'ticket_id' => $lockedResult->ticket_id,
                'ticket_rule_id' => $lockedResult->ticket_rule_id,
                'rule_version_id' => $lockedResult->rule_version_id,
                'branch' => $lockedResult->branch,
                'position' => $lockedResult->position,
                'action_type' => $lockedResult->action_type,
                'position_key' => $lockedResult->position_key,
                'attempt_number' => $attemptNumber,
                'retry_of_id' => $lockedResult->id,
                'precondition_fingerprint' => $precondition,
                'idempotency_key' => TicketRuleStableJson::checksum([
                    'position_key' => $lockedResult->position_key,
                    'attempt_number' => $attemptNumber,
                ]),
                'action_snapshot_json' => $lockedResult->action_snapshot_json,
                'status' => TicketRuleActionResult::STATUS_PLANNED,
            ]);
        }, 3);
    }
}
