<?php

namespace App\Modules\Storage\Jobs;

use App\Modules\Storage\Actions\ProcessPurchaseOrderImport;
use App\Modules\Storage\Actions\UpdateSupplierOrderImportOperationalState;
use App\Modules\Storage\Models\PurchaseOrderImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessScheduledSupplierOrderImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    private const ACTIVE_DISPATCH_STATUSES = [
        'claimed',
        'dispatched',
        'running',
    ];

    public int $timeout = 240;

    public function __construct(
        public readonly int $importId,
        public readonly string $claimToken,
    ) {
        $this->onQueue('supplier-orders');
    }

    public function handle(
        ProcessPurchaseOrderImport $process,
        UpdateSupplierOrderImportOperationalState $operationalState,
    ): void {
        $dispatch = DB::table('storage_purchase_order_import_dispatches')
            ->where('import_id', $this->importId)
            ->where('claim_token', $this->claimToken)
            ->first();
        $import = PurchaseOrderImport::query()->find($this->importId);
        if (! $dispatch || ! $import
            || $import->status !== PurchaseOrderImport::STATUS_PROCESSING
            || $import->reason_code !== 'scheduled_dispatch_claimed'
            || data_get($import->reason_context, 'claim_token') !== $this->claimToken) {
            // A duplicate delivery may observe this token while its first worker is already running.
            // Only that worker or the claim-scoped failure callback may complete the dispatch row.
            return;
        }

        $startedAt = now();
        $started = DB::table('storage_purchase_order_import_dispatches')
            ->where('id', $dispatch->id)
            ->where('claim_token', $this->claimToken)
            ->whereIn('status', ['claimed', 'dispatched'])
            ->update(['status' => 'running', 'worker_started_at' => $startedAt, 'updated_at' => $startedAt]);
        if ($started !== 1) {
            return;
        }

        $operationalState->handle(['worker_heartbeat_at' => $startedAt]);

        try {
            $result = $process->handle($import, $this->claimToken);
            $this->completeDispatch($result->status);
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $now = now();
        DB::transaction(function () use ($exception, $now): void {
            // Match dispatcher lock order: import first, then its current claim row.
            $import = PurchaseOrderImport::query()
                ->with('policyRevision')
                ->lockForUpdate()
                ->find($this->importId);
            $dispatch = DB::table('storage_purchase_order_import_dispatches')
                ->where('import_id', $this->importId)
                ->where('claim_token', $this->claimToken)
                ->whereIn('status', self::ACTIVE_DISPATCH_STATUSES)
                ->lockForUpdate()
                ->first();
            if (! $dispatch || ! $import) {
                return;
            }

            if ($import->status !== PurchaseOrderImport::STATUS_PROCESSING) {
                return;
            }

            $failed = DB::table('storage_purchase_order_import_dispatches')
                ->where('id', $dispatch->id)
                ->where('claim_token', $this->claimToken)
                ->whereIn('status', self::ACTIVE_DISPATCH_STATUSES)
                ->update([
                    'status' => 'failed',
                    'worker_completed_at' => $now,
                    'last_outcome' => $exception ? mb_substr($exception::class, 0, 255) : 'queue_job_failed',
                    'updated_at' => $now,
                ]);
            if ($failed !== 1) {
                return;
            }

            $retryLimit = max(0, (int) data_get($import->policyRevision?->snapshot, 'retry_limit', 3));
            $retry = (int) $dispatch->dispatch_count <= $retryLimit;
            $import->forceFill([
                'status' => $retry
                    ? PurchaseOrderImport::STATUS_RETRY_SCHEDULED
                    : PurchaseOrderImport::STATUS_FAILED,
                'reason_code' => $retry ? 'queue_job_failed' : 'retry_limit_exhausted',
                'reason_context' => [
                    'exception_class' => $exception ? $exception::class : null,
                    'failed_claim_token' => $this->claimToken,
                ],
                'next_retry_at' => $retry ? $now->copy()->addMinute() : null,
                'locked_at' => null,
                'processed_at' => $now,
            ])->save();
        });

        app(UpdateSupplierOrderImportOperationalState::class)->handle(['worker_heartbeat_at' => $now]);
    }

    private function completeDispatch(string $outcome): void
    {
        $now = now();
        DB::table('storage_purchase_order_import_dispatches')
            ->where('import_id', $this->importId)
            ->where('claim_token', $this->claimToken)
            ->whereIn('status', self::ACTIVE_DISPATCH_STATUSES)
            ->update([
                'status' => 'completed',
                'worker_completed_at' => $now,
                'last_outcome' => mb_substr($outcome, 0, 255),
                'updated_at' => $now,
            ]);
    }
}
