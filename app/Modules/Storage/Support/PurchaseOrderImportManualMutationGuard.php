<?php

namespace App\Modules\Storage\Support;

use App\Modules\Storage\Models\PurchaseOrderImport;
use Illuminate\Validation\ValidationException;

final class PurchaseOrderImportManualMutationGuard
{
    /**
     * Assert the state of an import after its row has been locked for a manual mutation.
     */
    public static function ensureMutable(PurchaseOrderImport $import, string $errorKey): void
    {
        if ($import->status === PurchaseOrderImport::STATUS_PROCESSING) {
            throw ValidationException::withMessages([
                $errorKey => 'This supplier-order import is currently processing and cannot be changed manually.',
            ]);
        }

        if ($import->purchase_order_id !== null || in_array($import->status, [
            PurchaseOrderImport::STATUS_IMPORTED,
            PurchaseOrderImport::STATUS_DUPLICATE,
            PurchaseOrderImport::STATUS_REJECTED,
            PurchaseOrderImport::STATUS_CANCELLED,
        ], true)) {
            throw ValidationException::withMessages([
                $errorKey => 'This supplier-order import is no longer editable.',
            ]);
        }
    }
}
