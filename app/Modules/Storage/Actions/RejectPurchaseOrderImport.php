<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Support\PurchaseOrderImportManualMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectPurchaseOrderImport
{
    public function handle(PurchaseOrderImport $import, User $actor, string $reason): PurchaseOrderImport
    {
        if (! $actor->isActive() || ! $actor->can('storage.purchase_import_execute')) {
            throw ValidationException::withMessages(['import' => 'You are not allowed to reject supplier-order imports.']);
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'A reason of at most 1000 characters is required.']);
        }

        return DB::transaction(function () use ($import, $actor, $reason): PurchaseOrderImport {
            $locked = PurchaseOrderImport::query()->lockForUpdate()->findOrFail($import->id);
            PurchaseOrderImportManualMutationGuard::ensureMutable($locked, 'import');
            if ($locked->purchase_order_id || in_array($locked->status, [
                PurchaseOrderImport::STATUS_IMPORTED,
                PurchaseOrderImport::STATUS_DUPLICATE,
                PurchaseOrderImport::STATUS_CANCELLED,
            ], true)) {
                throw ValidationException::withMessages(['import' => 'This import can no longer be rejected.']);
            }

            $locked->forceFill([
                'status' => PurchaseOrderImport::STATUS_REJECTED,
                'reason_code' => 'manually_rejected',
                'reason_context' => ['reason' => $reason],
                'last_actor_id' => $actor->id,
                'processed_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
