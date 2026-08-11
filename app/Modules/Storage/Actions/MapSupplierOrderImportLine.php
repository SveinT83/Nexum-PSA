<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use Illuminate\Validation\ValidationException;

class MapSupplierOrderImportLine
{
    public function __construct(private readonly SaveSupplierItemMapping $saveMapping) {}

    public function handle(
        PurchaseOrderImportLine $line,
        Item $item,
        User $actor,
    ): PurchaseOrderImportLine {
        if (! $actor->isActive() || ! $actor->can('storage.purchase_import_resolve')) {
            throw ValidationException::withMessages([
                'item_id' => 'You are not allowed to resolve supplier-order Items.',
            ]);
        }

        $import = $line->import;
        if (! $import || in_array($import->status, [
            'imported', 'duplicate', 'rejected', 'cancelled',
        ], true)) {
            throw ValidationException::withMessages([
                'item_id' => 'This supplier-order import is no longer editable.',
            ]);
        }

        return $this->saveMapping->handle(
            line: $line,
            item: $item,
            method: 'manual_confirmed',
            actor: $actor,
            requireOrderable: true,
            manualMutation: true,
        );
    }
}
