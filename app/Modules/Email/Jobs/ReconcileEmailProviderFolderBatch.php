<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderReconciliationScanner;
use App\Modules\Email\Support\EmailAccountProviderLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileEmailProviderFolderBatch implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 20;

    public int $maxExceptions = 8;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [10, 20, 60];

    public function __construct(public int $folderRunId)
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-provider-reconciliation-folder:'.$this->folderRunId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        $accountId = (int) EmailProviderReconciliationFolder::query()
            ->whereKey($this->folderRunId)
            ->value('account_id');

        return [EmailAccountProviderLock::middleware($accountId, $this->timeout)];
    }

    public function handle(
        EmailProviderReconciliationScanner $scanner,
        EmailProviderReconciliationReader $reader,
    ): void {
        $folder = EmailProviderReconciliationFolder::query()->find($this->folderRunId);
        if (! $folder) {
            return;
        }
        $run = EmailProviderReconciliationRun::query()->find(
            $folder->email_provider_reconciliation_run_id,
        );
        if (! $run || $run->terminal()) {
            return;
        }
        if ($run->cancellation_requested_at !== null) {
            TransitionEmailProviderReconciliationCancellation::dispatch((int) $run->id)
                ->afterCommit();

            return;
        }

        $result = $scanner->scanOnePage($folder, $reader);
        $dispatched = DB::transaction(function () use ($result, $run): bool {
            $locked = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($run->id);
            if (! $locked || $locked->terminal()
                || $locked->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || $locked->cancellation_requested_at !== null
                || (int) $locked->active_slot !== 1
                || $locked->status !== EmailProviderReconciliationRun::STATUS_RUNNING
                || $locked->phase !== EmailProviderReconciliationRun::PHASE_SCAN
                || $locked->final_summary_status !== null) {
                return false;
            }

            foreach ($result['import_item_ids'] as $itemId) {
                ImportEmailProviderReconciliationItem::dispatch($itemId);
            }
            if ($result['folder_finished']) {
                FinalizeEmailProviderReconciliation::dispatch((int) $locked->id);
            } else {
                self::dispatch($this->folderRunId)->delay(now()->addSecond());
            }

            return true;
        }, 3);
        if (! $dispatched && EmailProviderReconciliationRun::query()
            ->whereKey($run->id)
            ->whereNotNull('cancellation_requested_at')
            ->exists()) {
            TransitionEmailProviderReconciliationCancellation::dispatch((int) $run->id)
                ->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $runId = (int) EmailProviderReconciliationFolder::query()
            ->whereKey($this->folderRunId)
            ->value('email_provider_reconciliation_run_id');

        $failed = DB::transaction(function () use ($runId): bool {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($runId);
            $folder = EmailProviderReconciliationFolder::query()
                ->lockForUpdate()
                ->find($this->folderRunId);
            if (! $run || ! $folder
                || $run->terminal()
                || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || $run->cancellation_requested_at !== null
                || (int) $run->active_slot !== 1
                || $run->status !== EmailProviderReconciliationRun::STATUS_RUNNING
                || $run->phase !== EmailProviderReconciliationRun::PHASE_SCAN
                || $run->final_summary_status !== null
                || (int) $folder->email_provider_reconciliation_run_id !== (int) $run->id
                || $folder->item_summary_status !== null
                || ! in_array($folder->status, [
                    EmailProviderReconciliationFolder::STATUS_PENDING,
                    EmailProviderReconciliationFolder::STATUS_SCANNING,
                ], true)) {
                return false;
            }

            $folder->forceFill([
                'status' => EmailProviderReconciliationFolder::STATUS_FAILED,
                'reason_code' => 'provider_folder_scan_failed',
                'finished_at' => now(),
            ])->save();

            return true;
        }, 3);

        // A terminal folder failure must release the account active slot via
        // normal finalization instead of leaving the run stranded forever.
        if ($failed && $runId > 0) {
            FinalizeEmailProviderReconciliation::dispatch($runId);
        } elseif ($runId > 0 && EmailProviderReconciliationRun::query()
            ->whereKey($runId)
            ->whereNotNull('cancellation_requested_at')
            ->exists()) {
            TransitionEmailProviderReconciliationCancellation::dispatch($runId);
        }
    }
}
