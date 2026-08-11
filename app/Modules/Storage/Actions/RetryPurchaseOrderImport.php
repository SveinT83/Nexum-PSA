<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Jobs\ProcessSupplierOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryPurchaseOrderImport
{
    public function handle(PurchaseOrderImport $import, User $actor): PurchaseOrderImport
    {
        $this->authorize($actor);

        $retry = DB::transaction(function () use ($import, $actor): PurchaseOrderImport {
            $locked = PurchaseOrderImport::query()->lockForUpdate()->findOrFail($import->id);
            if (! in_array($locked->status, [
                PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                PurchaseOrderImport::STATUS_FAILED,
                PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
            ], true)) {
                throw ValidationException::withMessages(['import' => 'This import cannot be retried in its current state.']);
            }

            $locked->forceFill([
                'status' => PurchaseOrderImport::STATUS_PENDING,
                'stage' => PurchaseOrderImport::STAGE_DETECT,
                'reason_code' => null,
                'reason_context' => null,
                'next_retry_at' => null,
                'last_actor_id' => $actor->id,
            ])->save();

            return $locked;
        });

        ProcessSupplierOrderImport::dispatch($retry->id)->afterCommit();

        return $retry->fresh();
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->can('storage.purchase_import_execute')) {
            throw ValidationException::withMessages(['import' => 'You are not allowed to retry supplier-order imports.']);
        }
    }
}
