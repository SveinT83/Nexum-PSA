<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Actions\ProjectHistoricalEmailReadBaseline;
use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderReconciliationCancellationTransition;
use App\Modules\Email\Services\EmailProviderReconciliationFinalizer;
use App\Modules\Email\Services\EmailProviderReconciliationImporter;
use App\Modules\Email\Support\EmailAccountProviderLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class FinalizeEmailProviderReconciliation implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 40;

    public int $maxExceptions = 10;

    public int $uniqueFor = 900;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public function __construct(public int $runId)
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-provider-reconciliation-finalize:'.$this->runId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        $accountId = (int) EmailProviderReconciliationRun::query()
            ->whereKey($this->runId)
            ->value('account_id');

        return [EmailAccountProviderLock::middleware($accountId, $this->timeout)];
    }

    public function handle(
        EmailProviderReconciliationCancellationTransition $cancellation,
        EmailProviderReconciliationFinalizer $finalizer,
        EmailProviderReconciliationReader $reader,
    ): void {
        $run = EmailProviderReconciliationRun::query()->find($this->runId);
        if (! $run || $run->terminal()) {
            return;
        }

        if ($run->cancellation_requested_at !== null
            && $run->status !== EmailProviderReconciliationRun::STATUS_CANCELLING) {
            $cancellation->transition((int) $run->id);
            $run = $run->fresh();
        }
        if ($run->cancellation_requested_at !== null
            && $run->status !== EmailProviderReconciliationRun::STATUS_CANCELLING) {
            TransitionEmailProviderReconciliationCancellation::dispatch((int) $run->id)
                ->afterCommit();

            return;
        }

        $this->dispatchRecoveriesIfActive($run);

        if (! $finalizer->finalizeOneStep($run, $reader)) {
            self::dispatch($this->runId)->delay(now()->addSeconds(2));
        }
    }

    /** Serialize queue recovery against the durable cancellation intent. */
    private function dispatchRecoveriesIfActive(EmailProviderReconciliationRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            if (! $locked || $locked->terminal()
                || (int) $locked->active_slot !== 1) {
                return;
            }

            if ($locked->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || $locked->cancellation_requested_at !== null) {
                $this->dispatchAwaitingFanoutRecovery($locked);

                return;
            }

            $this->dispatchImportRecovery($locked);
            $this->dispatchHistoricalBaselineRecovery($locked);
            $this->dispatchAutomationRecovery($locked);
        }, 3);
    }

    public function failed(?Throwable $exception): void
    {
        $awaitingRunId = DB::transaction(function (): ?int {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($this->runId);
            if (! $run || $run->terminal() || (int) $run->active_slot !== 1) {
                return null;
            }

            // A fanout owns the durable completion barrier after rule effects
            // have committed. Never release the run slot from a failed queue
            // callback while that child can still settle the item.
            if (EmailProviderReconciliationItem::query()
                ->where('email_provider_reconciliation_run_id', $run->id)
                ->where(
                    'automation_status',
                    EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
                )
                ->exists()) {
                return (int) $run->id;
            }

            if ($run->cancellation_requested_at !== null
                || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || ! in_array($run->phase, [
                    EmailProviderReconciliationRun::PHASE_SCAN,
                    EmailProviderReconciliationRun::PHASE_IMPORTS,
                    EmailProviderReconciliationRun::PHASE_FINALIZE,
                    EmailProviderReconciliationRun::PHASE_DISCOVER_END,
                    EmailProviderReconciliationRun::PHASE_SUMMARY,
                ], true)) {
                return null;
            }

            $run->forceFill([
                ...EmailProviderReconciliationRun::emptyFinalSummary(),
                'status' => EmailProviderReconciliationRun::STATUS_FAILED,
                'phase' => $run->phase === EmailProviderReconciliationRun::PHASE_SUMMARY
                    ? EmailProviderReconciliationRun::PHASE_DISCOVER_END
                    : $run->phase,
                'active_slot' => null,
                'failure_code' => 'provider_finalization_failed',
                'finished_at' => now(),
            ])->save();

            return null;
        }, 3);

        if ($awaitingRunId === null) {
            return;
        }

        $run = EmailProviderReconciliationRun::query()->find($awaitingRunId);
        if (! $run || $run->terminal() || (int) $run->active_slot !== 1) {
            return;
        }

        $this->dispatchAwaitingFanoutRecovery($run);
        if ($run->cancellation_requested_at !== null
            || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING) {
            TransitionEmailProviderReconciliationCancellation::dispatch($awaitingRunId)
                ->afterCommit();

            return;
        }

        self::dispatch($awaitingRunId)->delay(now()->addSeconds(2))->afterCommit();
    }

    /**
     * Recover the crash window between committing a metadata cursor and
     * enqueueing its import IDs, plus claims abandoned by dead workers. The
     * sweep is bounded; this finalizer is itself durably re-enqueued while
     * imports remain nonterminal.
     */
    private function dispatchImportRecovery(EmailProviderReconciliationRun $run): void
    {
        $base = fn () => EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT);
        $cutoff = now()->subSeconds(EmailProviderReconciliationImporter::ABANDONED_CLAIM_SECONDS);
        $ids = $this->fairRecoveryIds([
            fn (int $limit) => $base()
                ->where('status', EmailProviderReconciliationItem::STATUS_PENDING)
                ->orderBy('last_attempt_at')->orderBy('id')->limit($limit)->pluck('id'),
            fn (int $limit) => $base()
                ->where('status', EmailProviderReconciliationItem::STATUS_RUNNING)
                ->whereNull('last_attempt_at')
                ->orderBy('id')->limit($limit)->pluck('id'),
            fn (int $limit) => $base()
                ->where('status', EmailProviderReconciliationItem::STATUS_RUNNING)
                ->whereNotNull('last_attempt_at')
                ->where('last_attempt_at', '<=', $cutoff)
                ->orderBy('last_attempt_at')->orderBy('id')->limit($limit)->pluck('id'),
        ]);

        foreach ($ids as $itemId) {
            ImportEmailProviderReconciliationItem::dispatch($itemId);
        }
    }

    /**
     * Recover the bounded crash window after an accepted import and reclaim
     * token-owned automation left by a worker that exceeded its queue timeout.
     */
    private function dispatchAutomationRecovery(EmailProviderReconciliationRun $run): void
    {
        $base = fn () => EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->where('automation_required', true);
        $cutoff = now()->subSeconds(
            ProcessEmailProviderReconciliationAutomation::ABANDONED_CLAIM_SECONDS,
        );
        $ids = $this->fairRecoveryIds([
            fn (int $limit) => $base()
                ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_PENDING)
                ->orderBy('automation_last_attempt_at')->orderBy('id')->limit($limit)->pluck('id'),
            fn (int $limit) => $base()
                ->where(
                    'automation_status',
                    EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
                )
                ->orderBy('automation_last_attempt_at')->orderBy('id')->limit($limit)->pluck('id'),
            fn (int $limit) => $base()
                ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_RUNNING)
                ->whereNull('automation_last_attempt_at')
                ->orderBy('id')->limit($limit)->pluck('id'),
            fn (int $limit) => $base()
                ->where('automation_status', EmailProviderReconciliationItem::AUTOMATION_RUNNING)
                ->whereNotNull('automation_last_attempt_at')
                ->where('automation_last_attempt_at', '<=', $cutoff)
                ->orderBy('automation_last_attempt_at')->orderBy('id')->limit($limit)->pluck('id'),
        ]);

        foreach ($ids as $itemId) {
            ProcessEmailProviderReconciliationAutomation::dispatch($itemId);
        }
    }

    /** Awaiting fanouts keep draining after cancellation intent wins. */
    private function dispatchAwaitingFanoutRecovery(EmailProviderReconciliationRun $run): void
    {
        EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->where('automation_required', true)
            ->where(
                'automation_status',
                EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
            )
            ->orderBy('automation_last_attempt_at')
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(fn (mixed $itemId) => ProcessEmailProviderReconciliationAutomation::dispatch(
                (int) $itemId,
            )->afterCommit());
    }

    /**
     * Recover queue-loss and stale token claims for the DB-only historical
     * read projection. The job itself owns no provider/cache lock and each
     * invocation advances at most one hard-capped baseline page.
     */
    private function dispatchHistoricalBaselineRecovery(EmailProviderReconciliationRun $run): void
    {
        $base = fn () => EmailProviderReconciliationItem::query()
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('kind', EmailProviderReconciliationItem::KIND_IMPORT)
            ->where('historical_baseline_required', true);
        $cutoff = now()->subSeconds(
            ProjectHistoricalEmailReadBaseline::ABANDONED_RECONCILIATION_CLAIM_SECONDS,
        );
        $ids = $this->fairRecoveryIds([
            fn (int $limit) => $base()
                ->where(
                    'historical_baseline_status',
                    EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
                )
                ->orderBy('historical_baseline_last_attempt_at')
                ->orderBy('id')->limit($limit)->pluck('id'),
            fn (int $limit) => $base()
                ->where(
                    'historical_baseline_status',
                    EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
                )
                ->whereNull('historical_baseline_last_attempt_at')
                ->orderBy('id')->limit($limit)->pluck('id'),
            fn (int $limit) => $base()
                ->where(
                    'historical_baseline_status',
                    EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
                )
                ->whereNotNull('historical_baseline_last_attempt_at')
                ->where('historical_baseline_last_attempt_at', '<=', $cutoff)
                ->orderBy('historical_baseline_last_attempt_at')
                ->orderBy('id')->limit($limit)->pluck('id'),
        ]);

        foreach ($ids as $itemId) {
            ProjectEmailProviderHistoricalReadBaseline::dispatch($itemId)->afterCommit();
        }
    }

    /**
     * Give every exact recovery branch a share of one hard cap. Empty branches
     * donate their quota, while a hot status cannot starve another status.
     *
     * @param  array<int, callable(int):iterable<mixed>>  $branches
     * @return array<int, int>
     */
    private function fairRecoveryIds(array $branches, int $limit = 100): array
    {
        $ids = [];
        $seen = [];
        $branchCount = count($branches);
        if ($branchCount === 0 || $limit < 1) {
            return [];
        }

        $quotas = [];
        $baseQuota = intdiv($limit, $branchCount);
        $remainder = $limit % $branchCount;

        foreach ($branches as $offset => $branch) {
            $quota = $baseQuota + ($offset < $remainder ? 1 : 0);
            $quotas[$offset] = $quota;
            $this->appendRecoveryIds($ids, $seen, $branch($quota), $limit);
        }

        // A second, still constant, pass donates every unused share back to
        // hot branches. Each branch query remains capped at the global limit.
        foreach ($branches as $offset => $branch) {
            $remaining = $limit - count($ids);
            if ($remaining < 1) {
                break;
            }

            $this->appendRecoveryIds(
                $ids,
                $seen,
                $branch(min($limit, $quotas[$offset] + $remaining)),
                $limit,
            );
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, true>  $seen
     * @param  iterable<mixed>  $candidates
     */
    private function appendRecoveryIds(
        array &$ids,
        array &$seen,
        iterable $candidates,
        int $limit,
    ): void {
        foreach ($candidates as $rawId) {
            if (count($ids) >= $limit) {
                return;
            }

            $id = (int) $rawId;
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }
    }
}
