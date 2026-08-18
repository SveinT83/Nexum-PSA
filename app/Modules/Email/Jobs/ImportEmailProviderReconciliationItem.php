<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Contracts\EmailProviderReconciliationMessageStore;
use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderReconciliationImporter;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Email\Support\EmailAccountProviderLockContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportEmailProviderReconciliationItem implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public int $tries = 12;

    public int $maxExceptions = 8;

    public int $uniqueFor = 900;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    public function __construct(public int $itemId)
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-provider-reconciliation-import:'.$this->itemId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        $accountId = (int) EmailProviderReconciliationItem::query()
            ->whereKey($this->itemId)
            ->value('email_provider_reconciliation_run_id');
        $accountId = $accountId > 0
            ? (int) \App\Modules\Email\Models\EmailProviderReconciliationRun::query()
                ->whereKey($accountId)
                ->value('account_id')
            : 0;

        return [EmailAccountProviderLock::middleware($accountId, $this->timeout)];
    }

    public function handle(
        EmailProviderReconciliationImporter $importer,
        EmailProviderReconciliationReader $reader,
        EmailProviderReconciliationMessageStore $store,
    ): void {
        $item = EmailProviderReconciliationItem::query()->find($this->itemId);
        if (! $item) {
            return;
        }

        $accountId = (int) EmailProviderReconciliationRun::query()
            ->whereKey($item->email_provider_reconciliation_run_id)
            ->value('account_id');
        if ($accountId < 1) {
            return;
        }
        $status = EmailAccountProviderLockContext::withinHeld(
            $accountId,
            fn (): string => $importer->importOne($item, $reader, $store),
        );
        if ($status === EmailProviderReconciliationItem::STATUS_RUNNING) {
            // A redelivered job may observe a still-fresh claim from a worker
            // that died after commit. Preserve a delayed recovery attempt;
            // the item row remains the only authority for who may claim it.
            self::dispatch($this->itemId)->delay(now()->addSeconds(
                EmailProviderReconciliationImporter::ABANDONED_CLAIM_SECONDS,
            ));
        } elseif ($status === EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE) {
            ProjectEmailProviderHistoricalReadBaseline::dispatch($this->itemId)->afterCommit();
        }
        FinalizeEmailProviderReconciliation::dispatch(
            (int) $item->email_provider_reconciliation_run_id,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $runId = (int) EmailProviderReconciliationItem::query()
            ->whereKey($this->itemId)
            ->value('email_provider_reconciliation_run_id');

        $failed = DB::transaction(function () use ($runId): bool {
            $reference = EmailProviderReconciliationItem::query()
                ->select(['id', 'email_provider_reconciliation_folder_id'])
                ->find($this->itemId);
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($runId);
            $folder = $reference
                ? EmailProviderReconciliationFolder::query()
                    ->lockForUpdate()
                    ->find($reference->email_provider_reconciliation_folder_id)
                : null;
            $item = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($this->itemId);
            if (! $run || ! $folder || ! $item
                || $run->terminal()
                || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || $run->cancellation_requested_at !== null
                || (int) $run->active_slot !== 1
                || (int) $folder->email_provider_reconciliation_run_id !== (int) $run->id
                || $run->final_summary_status !== null
                || $folder->item_summary_status !== null
                || ! (($run->status === EmailProviderReconciliationRun::STATUS_RUNNING
                        && $run->phase === EmailProviderReconciliationRun::PHASE_SCAN
                        && $folder->status === EmailProviderReconciliationFolder::STATUS_SCANNING)
                    || ($run->status === EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS
                        && $run->phase === EmailProviderReconciliationRun::PHASE_IMPORTS
                        && $folder->status
                            === EmailProviderReconciliationFolder::STATUS_WAITING_FOR_IMPORTS))
                || (int) $item->email_provider_reconciliation_run_id !== (int) $run->id
                || ! in_array($item->status, [
                    EmailProviderReconciliationItem::STATUS_PENDING,
                    EmailProviderReconciliationItem::STATUS_RUNNING,
                ], true)) {
                return false;
            }

            $item->forceFill([
                'status' => EmailProviderReconciliationItem::STATUS_FAILED,
                'error_code' => 'provider_import_failed',
                'completed_at' => now(),
            ])->save();
            $run->markAutomationScopeUnsafe();

            return true;
        }, 3);

        // A failed import is terminal evidence. Finalization records a
        // partial/stale outcome and clears the account active slot.
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
