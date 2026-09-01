<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailLiveUserAccessState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Advances one exact user's authority scan by one raw page of at most 100 rows. */
class EmailLiveAccessRecomputeService
{
    public function processPending(int $stateLimit = 25): int
    {
        $processed = 0;
        EmailLiveUserAccessState::query()
            ->where('recompute_status', EmailLiveUserAccessState::STATUS_PENDING)
            ->orderBy('id')
            ->limit(min(25, max(1, $stateLimit)))
            ->pluck('id')
            ->each(function (int $stateId) use (&$processed): void {
                if ($this->processNextPage($stateId)) {
                    $processed++;
                }
            });

        return $processed;
    }

    public function processNextPage(int $stateId): bool
    {
        $claim = null;

        try {
            $claim = DB::transaction(fn (): ?array => $this->claim($stateId));
            if (! $claim) {
                return false;
            }

            DB::transaction(fn () => $this->commit($stateId, $claim));

            return true;
        } catch (Throwable) {
            if ($claim) {
                DB::transaction(fn () => $this->fail($stateId, $claim['token']));
            }

            return false;
        }
    }

    /** @return array{token: string, through_id: int, row_count: int, phase: string}|null */
    private function claim(int $stateId): ?array
    {
        $state = EmailLiveUserAccessState::query()->lockForUpdate()->find($stateId);
        if (! $state || $state->recompute_status !== EmailLiveUserAccessState::STATUS_PENDING) {
            return null;
        }

        $limit = min(100, max(1, (int) config('email_live.access_recompute_page_size', 100)));
        $phase = (string) $state->recompute_phase;
        $cursor = (int) $state->recompute_cursor_id;
        $through = $phase === EmailLiveUserAccessState::PHASE_DELEGATIONS
            ? (int) $state->delegation_through_id
            : (int) $state->break_glass_through_id;
        $table = $phase === EmailLiveUserAccessState::PHASE_DELEGATIONS
            ? 'email_mailbox_delegations'
            : 'email_break_glass_accesses';
        $userColumn = $phase === EmailLiveUserAccessState::PHASE_DELEGATIONS
            ? 'delegate_id'
            : 'actor_id';
        $rows = DB::table($table)
            ->where($userColumn, $state->user_id)
            ->where('id', '>', $cursor)
            ->where('id', '<=', $through)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);
        $pageThrough = $rows->isEmpty() ? $through : (int) $rows->last()->id;
        $token = hash('sha256', (string) Str::uuid());

        $state->update([
            'recompute_status' => EmailLiveUserAccessState::STATUS_RUNNING,
            'claim_token' => $token,
            'page_through_id' => $pageThrough,
            'page_row_count' => $rows->count(),
            'attempt_count' => (int) $state->attempt_count + 1,
            'last_attempt_at' => now(),
            'error_code' => null,
        ]);

        return [
            'token' => $token,
            'through_id' => $pageThrough,
            'row_count' => $rows->count(),
            'phase' => $phase,
        ];
    }

    /** @param array{token: string, through_id: int, row_count: int, phase: string} $claim */
    private function commit(int $stateId, array $claim): void
    {
        $state = EmailLiveUserAccessState::query()
            ->whereKey($stateId)
            ->where('recompute_status', EmailLiveUserAccessState::STATUS_RUNNING)
            ->where('claim_token', $claim['token'])
            ->lockForUpdate()
            ->first();
        if (! $state) {
            return;
        }

        $phaseThrough = $claim['phase'] === EmailLiveUserAccessState::PHASE_DELEGATIONS
            ? (int) $state->delegation_through_id
            : (int) $state->break_glass_through_id;
        $phaseComplete = $claim['through_id'] >= $phaseThrough;
        $seal = $phaseComplete && $claim['phase'] === EmailLiveUserAccessState::PHASE_BREAK_GLASS;
        $nextPhase = $phaseComplete && ! $seal
            ? EmailLiveUserAccessState::PHASE_BREAK_GLASS
            : $claim['phase'];

        $state->update([
            'recompute_status' => $seal
                ? EmailLiveUserAccessState::STATUS_SEALED
                : EmailLiveUserAccessState::STATUS_PENDING,
            'recompute_phase' => $seal ? null : $nextPhase,
            'recompute_cursor_id' => $phaseComplete ? 0 : $claim['through_id'],
            'recompute_boundary_at' => $seal ? null : $state->recompute_boundary_at,
            'claim_token' => null,
            'page_through_id' => null,
            'page_row_count' => null,
            'page_count' => (int) $state->page_count + 1,
            'completed_at' => $seal ? now() : null,
            'error_code' => null,
        ]);
    }

    private function fail(int $stateId, string $token): void
    {
        $state = EmailLiveUserAccessState::query()
            ->whereKey($stateId)
            ->where('recompute_status', EmailLiveUserAccessState::STATUS_RUNNING)
            ->where('claim_token', $token)
            ->lockForUpdate()
            ->first();
        if (! $state) {
            return;
        }

        $blocked = (int) $state->attempt_count >= 3;
        $state->update([
            'recompute_status' => $blocked
                ? EmailLiveUserAccessState::STATUS_BLOCKED
                : EmailLiveUserAccessState::STATUS_PENDING,
            'claim_token' => null,
            'page_through_id' => null,
            'page_row_count' => null,
            'completed_at' => $blocked ? now() : null,
            'error_code' => $blocked ? 'email_live_access_attempts_exhausted' : 'email_live_access_page_failed',
        ]);
    }
}
