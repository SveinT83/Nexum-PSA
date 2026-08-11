<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Support\SupplierOrderIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

final class EnsurePurchaseOrderSupplierIdentityAvailable
{
    public function handle(
        int $vendorId,
        ?string $supplierOrderNumber,
        ?int $ignorePurchaseOrderId = null,
    ): ?string {
        $normalized = SupplierOrderIdentity::normalize($supplierOrderNumber);
        if ($vendorId < 1 || $normalized === null) {
            return null;
        }

        $query = PurchaseOrder::withTrashed()
            ->where('vendor_id', $vendorId)
            ->whereRaw(
                "supplier_order_identity_key = NULLIF(UPPER(TRIM(?)), '')",
                [$supplierOrderNumber],
            );
        if ($ignorePurchaseOrderId !== null) {
            $query->whereKeyNot($ignorePurchaseOrderId);
        }

        $existing = $query
            ->lockForUpdate()
            ->first(['id', 'po_number', 'status', 'deleted_at']);
        if ($existing !== null) {
            throw ValidationException::withMessages([
                'vendor_ref' => 'This supplier order number is already registered as Nexum order '
                    .$existing->po_number.'. Open the existing order instead.',
            ]);
        }

        return $normalized;
    }

    public static function throwWhenConstraintWasRaced(QueryException $exception): never
    {
        $message = strtolower($exception->getMessage());
        if (! str_contains($message, 'supplier_order_identity')
            && ! str_contains($message, 'storage_po_supplier_order_identity_key_unique')
            && ! str_contains($message, 'storage_po_supplier_order_identity_unique')) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'vendor_ref' => 'This supplier order number was registered by another request. '
                .'Open the existing purchase order instead.',
        ]);
    }
}
