<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\StoreBox;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\StorageInventoryDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BoxController extends Controller
{
    public function create(StorageInventoryDefaults $inventoryDefaults): View
    {
        return view('storage::Tech.Storage.boxes.create', [
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'defaultWarehouse' => $inventoryDefaults->defaultWarehouse(),
        ]);
    }

    public function store(Request $request, StoreBox $storeBox): RedirectResponse
    {
        $box = $storeBox->handle($request->validate([
            'warehouse_id' => 'required|exists:storage_warehouses,id',
            'code_human' => 'nullable|string|max:32|unique:storage_boxes,code_human',
            'name' => 'nullable|string|max:120',
            'barcode_value' => 'nullable|string|max:255|unique:storage_boxes,barcode_value',
            'barcode_type' => 'required|string|in:QR,EAN13,CODE128',
            'status' => 'required|string|in:in_stock,in_transit,loaned,at_customer,lost,retired',
            'placement_note' => 'nullable|string|max:512',
            'is_active' => 'boolean',
        ]), $request->user());

        return redirect()->route('tech.storage.boxes.show', $box)
            ->with('success', 'Storage box created.');
    }

    public function show(Box $box): View
    {
        $box->load(['warehouse', 'items', 'events.actor']);

        return view('storage::Tech.Storage.boxes.show', compact('box'));
    }
}
