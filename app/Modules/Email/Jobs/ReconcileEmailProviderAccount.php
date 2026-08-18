<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderReconciliationCoordinator;
use App\Modules\Email\Support\EmailAccountProviderLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileEmailProviderAccount implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 10;

    public int $maxExceptions = 5;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    public function __construct(public int $runId)
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-provider-reconciliation-account:'.$this->runId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        $run = EmailProviderReconciliationRun::query()
            ->select(['account_id', 'phase'])
            ->find($this->runId);
        if (! $run || $run->phase !== EmailProviderReconciliationRun::PHASE_DISCOVER_START) {
            // Resumable local-folder pages are DB-only and must not hold the
            // distributed provider lock between bounded LIST and scan work.
            return [];
        }

        return [EmailAccountProviderLock::middleware((int) $run->account_id, $this->timeout)];
    }

    public function handle(
        EmailProviderReconciliationCoordinator $coordinator,
        EmailProviderReconciliationReader $reader,
    ): void {
        $run = EmailProviderReconciliationRun::query()->find($this->runId);
        if (! $run || $run->terminal()) {
            return;
        }
        if ($run->cancellation_requested_at !== null) {
            TransitionEmailProviderReconciliationCancellation::dispatch($this->runId)
                ->afterCommit();

            return;
        }
        if ($run->status === EmailProviderReconciliationRun::STATUS_CANCELLING) {
            FinalizeEmailProviderReconciliation::dispatch($this->runId);

            return;
        }

        $folderIds = in_array($run->phase, [
            EmailProviderReconciliationRun::PHASE_DISCOVER_START,
            EmailProviderReconciliationRun::PHASE_DISCOVER_LOCAL,
        ], true)
            ? $coordinator->discoverStart($run, $reader)
            : EmailProviderReconciliationFolder::query()
                ->where('email_provider_reconciliation_run_id', $run->id)
                ->whereIn(
                    'discovery_state',
                    EmailProviderReconciliationFolder::REMOTE_DISCOVERY_STATES,
                )
                ->whereIn('status', [
                    EmailProviderReconciliationFolder::STATUS_PENDING,
                    EmailProviderReconciliationFolder::STATUS_SCANNING,
                ])
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

        $dispatched = DB::transaction(function () use ($folderIds): bool {
            $locked = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($this->runId);
            if (! $locked || $locked->terminal()
                || $locked->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || $locked->cancellation_requested_at !== null
                || (int) $locked->active_slot !== 1) {
                return false;
            }
            if ($locked->phase === EmailProviderReconciliationRun::PHASE_DISCOVER_LOCAL) {
                // `ShouldBeUniqueUntilProcessing` releases before handle, so
                // the next bounded local-folder page can be queued here.
                self::dispatch($this->runId);

                return true;
            }

            foreach ($folderIds as $folderId) {
                ReconcileEmailProviderFolderBatch::dispatch($folderId);
            }
            if ($folderIds === []) {
                FinalizeEmailProviderReconciliation::dispatch($this->runId);
            }

            return true;
        }, 3);
        if (! $dispatched && EmailProviderReconciliationRun::query()
            ->whereKey($this->runId)
            ->whereNotNull('cancellation_requested_at')
            ->exists()) {
            TransitionEmailProviderReconciliationCancellation::dispatch($this->runId)
                ->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($this->runId);
            if (! $run || $run->terminal()
                || (int) $run->active_slot !== 1
                || $run->cancellation_requested_at !== null
                || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || ! in_array($run->phase, [
                    EmailProviderReconciliationRun::PHASE_DISCOVER_START,
                    EmailProviderReconciliationRun::PHASE_DISCOVER_LOCAL,
                ], true)) {
                return;
            }

            $run->forceFill([
                'status' => EmailProviderReconciliationRun::STATUS_FAILED,
                'active_slot' => null,
                'failure_code' => 'provider_start_discovery_failed',
                'finished_at' => now(),
            ])->save();
        }, 3);
    }
}
