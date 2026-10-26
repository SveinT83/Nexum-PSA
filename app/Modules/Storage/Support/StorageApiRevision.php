<?php

namespace App\Modules\Storage\Support;

use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class StorageApiRevision
{
    /** Lock the same domain root used by browser mutations before checking an API revision. */
    public static function lockTarget(Request $request): void
    {
        $kind = $request->route()->defaults['storage_revision'] ?? null;
        $parameter = $request->route()->defaults['storage_revision_parameter'] ?? $kind;
        $id = $parameter ? $request->route($parameter) : null;
        $id = $id instanceof Model ? $id->getKey() : $id;
        $query = match ($kind) {
            'item' => Item::withTrashed()->whereKey($id),
            'box' => Box::whereKey($id),
            'purchaseOrder' => PurchaseOrder::whereKey($id),
            'import' => PurchaseOrderImport::whereKey($id),
            'profile' => PurchaseOrderImportProfile::whereKey($id),
            'policy' => PurchaseOrderAutomationPolicy::where('is_current', true),
            'settings' => \App\Models\Settings\CommonSetting::where('type', 'storage')->where('name', 'inventory_defaults'),
            default => null,
        };
        $query?->lockForUpdate()->first();
    }

    /** Snapshot fresh parent and children; hashes reveal no hidden source fields. */
    public static function forRequest(Request $request): ?string
    {
        $kind = $request->route()->defaults['storage_revision'] ?? null;
        $parameter = $request->route()->defaults['storage_revision_parameter'] ?? $kind;
        $id = $request->route($parameter);
        $id = $id instanceof Model ? $id->getKey() : $id;
        $model = match ($kind) {
            'item' => Item::withTrashed()->with('itemVendors')->findOrFail($id),
            'box' => Box::with(['items', 'events'])->findOrFail($id),
            'purchaseOrder' => PurchaseOrder::with(['lines', 'shipments.lines', 'shipments.trackings', 'receipts.lines'])->findOrFail($id),
            'import' => PurchaseOrderImport::with(['lines', 'attempts', 'repairs'])->findOrFail($id),
            'profile' => PurchaseOrderImportProfile::with(['versions', 'fixtures', 'metadataAudits'])->findOrFail($id),
            'policy' => PurchaseOrderAutomationPolicy::where('is_current', true)->first(),
            'settings' => \App\Models\Settings\CommonSetting::where('type', 'storage')->where('name', 'inventory_defaults')->first(),
            default => null,
        };

        return $kind ? StorageApiInput::revision($model?->toArray() ?? ['unconfigured' => $kind]) : null;
    }
}
