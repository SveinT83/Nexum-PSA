<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use Illuminate\Validation\ValidationException;

class CreateItemForPurchaseOrderImportLine
{
    public function __construct(
        private readonly GetCurrentPurchaseOrderAutomationPolicy $currentPolicy,
        private readonly CreateSupplierImportedItem $createItem,
    ) {}

    public function handle(
        PurchaseOrderImportLine $line,
        User $actor,
        string $mode,
    ): Item {
        if (! $actor->isActive()
            || ! $actor->can('storage.purchase_import_resolve')
            || ! $actor->can('storage.purchase_manage')) {
            throw ValidationException::withMessages(['item' => 'You are not allowed to create an Item for this import.']);
        }
        if (! in_array($mode, ['create_review_item', 'create_active_item'], true)) {
            throw ValidationException::withMessages(['mode' => 'Unknown imported-Item mode.']);
        }

        $import = $line->import;
        if (! $import || in_array($import->status, ['imported', 'duplicate', 'rejected', 'cancelled'], true)) {
            throw ValidationException::withMessages(['item' => 'This import is no longer editable.']);
        }

        $storedPolicy = $this->currentPolicy->handle()['policy'];
        $policy = $storedPolicy->replicate();
        $policy->forceFill([
            'id' => $storedPolicy->id,
            'new_item_mode' => $mode,
        ]);

        return $this->createItem->handle(
            $import,
            $line,
            $policy,
            $actor,
            manualMutation: true,
        );
    }
}
