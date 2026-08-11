<?php

namespace App\Modules\Storage\Jobs;

use App\Modules\Storage\Actions\ProcessPurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessSupplierOrderImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 180;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('supplier-orders');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(ProcessPurchaseOrderImport $process): void
    {
        $import = PurchaseOrderImport::query()->find($this->importId);
        if (! $import || in_array($import->status, [
            PurchaseOrderImport::STATUS_IMPORTED,
            PurchaseOrderImport::STATUS_DUPLICATE,
            PurchaseOrderImport::STATUS_REJECTED,
            PurchaseOrderImport::STATUS_CANCELLED,
        ], true)) {
            return;
        }
        if ($import->status === PurchaseOrderImport::STATUS_RETRY_SCHEDULED
            && $import->next_retry_at?->isFuture()) {
            return;
        }

        $process->handle($import);
    }

    public function failed(?Throwable $exception): void
    {
        $import = PurchaseOrderImport::query()->find($this->importId);
        if (! $import || $import->status === PurchaseOrderImport::STATUS_IMPORTED) {
            return;
        }

        $import->forceFill([
            'status' => PurchaseOrderImport::STATUS_FAILED,
            'reason_code' => 'queue_job_failed',
            'reason_context' => ['exception_class' => $exception ? $exception::class : null],
            'processed_at' => now(),
        ])->save();
    }
}
