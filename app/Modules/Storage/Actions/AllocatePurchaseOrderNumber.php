<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class AllocatePurchaseOrderNumber
{
    public function handle(PurchaseOrder $purchaseOrder, ?string $requestedPoNumber = null): PurchaseOrder
    {
        $requestedPoNumber = filled($requestedPoNumber)
            ? trim((string) $requestedPoNumber)
            : null;

        $finalPoNumber = $requestedPoNumber ?: (string) $purchaseOrder->id;

        try {
            $purchaseOrder->forceFill([
                'po_number' => $finalPoNumber,
            ])->save();
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw ValidationException::withMessages([
                    'po_number' => 'This Nexum order number is already in use.',
                ]);
            }

            throw $exception;
        }

        return $purchaseOrder;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23000';
    }
}
