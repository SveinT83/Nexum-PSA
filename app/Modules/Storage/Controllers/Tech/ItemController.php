<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use App\Modules\Storage\Actions\AdjustItemStock;
use App\Modules\Storage\Actions\DeleteItem;
use App\Modules\Storage\Actions\StoreItem;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\ItemVendor;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\StorageInventoryDefaults;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class ItemController extends Controller
{
    public function create(EnsureEconomyDefaults $economyDefaults, StorageInventoryDefaults $inventoryDefaults): View
    {
        return view('storage::Tech.Storage.items.create', [
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'defaultWarehouse' => $inventoryDefaults->defaultWarehouse(),
            'boxes' => Box::where('is_active', true)->with('warehouse')->orderBy('id')->get(),
            'manufacturers' => Vendor::where('is_active', true)->where('is_manufacturer', true)->orderBy('name')->get(),
            'suppliers' => Vendor::where('is_active', true)->where('is_supplier', true)->orderBy('name')->get(),
            'defaultVatRate' => $economyDefaults->handle()->default_vat_rate,
        ]);
    }

    public function store(Request $request, StoreItem $storeItem): RedirectResponse
    {
        $data = $this->validatedItem($request);
        $itemData = $this->prepareItemData($data);
        $supplierData = $this->supplierData($data);

        $item = DB::transaction(function () use ($itemData, $supplierData, $request, $storeItem) {
            $item = $storeItem->handle($itemData, $request->user());
            $this->syncPrimarySupplier($item, $supplierData);

            return $item;
        });

        return redirect()->route('tech.storage.items.show', $item)
            ->with('success', 'Storage item created.');
    }

    public function show(Item $item): View
    {
        $item->load(['warehouse', 'box', 'primaryVendor', 'manufacturerVendor', 'itemVendors.vendor', 'movements.actor']);

        return view('storage::Tech.Storage.items.show', compact('item'));
    }

    public function edit(Item $item, EnsureEconomyDefaults $economyDefaults): View
    {
        return view('storage::Tech.Storage.items.edit', [
            'item' => $item,
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'boxes' => Box::where('is_active', true)->with('warehouse')->orderBy('id')->get(),
            'manufacturers' => Vendor::where('is_active', true)->where('is_manufacturer', true)->orderBy('name')->get(),
            'suppliers' => Vendor::where('is_active', true)->where('is_supplier', true)->orderBy('name')->get(),
            'primarySupplierLine' => $item->itemVendors()->where('is_primary', true)->first(),
            'defaultVatRate' => $economyDefaults->handle()->default_vat_rate,
        ]);
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => 'required|exists:storage_warehouses,id',
            'box_id' => 'nullable|exists:storage_boxes,id',
            'manufacturer_vendor_id' => 'nullable|exists:vendors,id',
            'primary_vendor_id' => 'nullable|exists:vendors,id',
            'supplier_sku' => 'nullable|string|max:255',
            'supplier_purchase_url' => 'nullable|url|max:2048',
            'supplier_currency' => 'nullable|string|size:3',
            'supplier_lead_time_days' => 'nullable|integer|min:0',
            'supplier_moq' => 'nullable|integer|min:1',
            'supplier_pack_size' => 'nullable|integer|min:1',
            'sku' => 'required|string|max:100|unique:storage_items,sku,' . $item->id,
            'name' => 'required|string|max:255',
            'short_description' => 'nullable|string',
            'long_description' => 'nullable|string',
            'manufacturer_part_number' => 'nullable|string|max:255',
            'ean_number' => 'nullable|string|max:100',
            'purchase_price' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'vat_rate' => 'nullable|numeric|min:0',
            'has_serials' => 'boolean',
            'reorder_point' => 'nullable|integer|min:0',
            'target_level' => 'nullable|integer|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'moq' => 'nullable|integer|min:1',
            'should_order' => 'boolean',
            'status' => 'required|string|in:active,inactive',
        ]);

        $itemData = $this->prepareItemData($data);
        $supplierData = $this->supplierData($data);

        $itemData['sku'] = strtoupper($itemData['sku']);
        $itemData['box_id'] = $itemData['box_id'] ?: null;
        $itemData['has_serials'] = $request->boolean('has_serials');
        $itemData['should_order'] = $request->boolean('should_order');
        $itemData['updated_by'] = $request->user()?->id;

        DB::transaction(function () use ($item, $itemData, $supplierData) {
            $item->update($itemData);
            $this->syncPrimarySupplier($item, $supplierData);
        });

        return redirect()->route('tech.storage.items.show', $item)
            ->with('success', 'Storage item updated.');
    }

    public function adjust(Request $request, Item $item, AdjustItemStock $adjustItemStock): RedirectResponse
    {
        $data = $request->validate([
            'adjustment_mode' => 'nullable|string|in:set,increase,decrease',
            'quantity' => 'nullable|required_with:adjustment_mode|integer|min:0',
            'delta' => 'nullable|required_without:adjustment_mode|integer|not_in:0',
            'reason' => 'required|string|max:100',
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $adjustItemStock->handle($item, $this->adjustmentDelta($item, $data), $data['reason'], $data['note'] ?? null, $request->user());
        } catch (InvalidArgumentException $exception) {
            $field = filled($data['adjustment_mode'] ?? null) ? 'quantity' : 'delta';

            return back()->withErrors([$field => $exception->getMessage()]);
        }

        return back()->with('success', 'Stock adjusted.');
    }

    public function destroy(Request $request, Item $item, DeleteItem $deleteItem): RedirectResponse
    {
        try {
            $deleteItem->handle($item, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['item' => $exception->getMessage()]);
        }

        return redirect()
            ->route('tech.storage.index')
            ->with('success', 'Storage item deleted.');
    }

    private function validatedItem(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => 'required|exists:storage_warehouses,id',
            'box_id' => 'nullable|exists:storage_boxes,id',
            'manufacturer_vendor_id' => 'nullable|exists:vendors,id',
            'primary_vendor_id' => 'nullable|exists:vendors,id',
            'supplier_sku' => 'nullable|string|max:255',
            'supplier_purchase_url' => 'nullable|url|max:2048',
            'supplier_currency' => 'nullable|string|size:3',
            'supplier_lead_time_days' => 'nullable|integer|min:0',
            'supplier_moq' => 'nullable|integer|min:1',
            'supplier_pack_size' => 'nullable|integer|min:1',
            'sku' => 'required|string|max:100|unique:storage_items,sku',
            'name' => 'required|string|max:255',
            'short_description' => 'nullable|string',
            'long_description' => 'nullable|string',
            'manufacturer_part_number' => 'nullable|string|max:255',
            'ean_number' => 'nullable|string|max:100',
            'purchase_price' => 'nullable|numeric|min:0',
            'markup_percent' => 'nullable|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'vat_rate' => 'nullable|numeric|min:0',
            'has_serials' => 'boolean',
            'reorder_point' => 'nullable|integer|min:0',
            'target_level' => 'nullable|integer|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'moq' => 'nullable|integer|min:1',
            'initial_quantity' => 'nullable|integer|min:0',
            'should_order' => 'boolean',
            'status' => 'required|string|in:active,inactive',
        ]);
    }

    private function adjustmentDelta(Item $item, array $data): int
    {
        if (! filled($data['adjustment_mode'] ?? null)) {
            return (int) $data['delta'];
        }

        $quantity = (int) $data['quantity'];

        $delta = match ($data['adjustment_mode']) {
            'set' => $quantity - (int) $item->qty_on_hand,
            'increase' => $quantity,
            'decrease' => -$quantity,
        };

        if ($delta === 0) {
            throw new InvalidArgumentException('Stock adjustment must change the on-hand quantity.');
        }

        return $delta;
    }

    private function prepareItemData(array $data): array
    {
        $manufacturer = $this->resolveVendor(
            $data['manufacturer_vendor_id'] ?? null,
            true,
            false
        );
        $supplier = $this->resolveVendor(
            $data['primary_vendor_id'] ?? null,
            false,
            true
        );

        $itemData = Arr::except($data, [
            'supplier_sku',
            'supplier_purchase_url',
            'supplier_currency',
            'supplier_lead_time_days',
            'supplier_moq',
            'supplier_pack_size',
        ]);

        $itemData['manufacturer_vendor_id'] = $manufacturer?->id;
        $itemData['primary_vendor_id'] = $supplier?->id;
        $itemData['manufacturer'] = $manufacturer?->name;

        return $itemData;
    }

    private function supplierData(array $data): array
    {
        return [
            'vendor_id' => $this->resolveVendor(
                $data['primary_vendor_id'] ?? null,
                false,
                true
            )?->id,
            'vendor_sku' => $data['supplier_sku'] ?? null,
            'purchase_url' => $data['supplier_purchase_url'] ?? null,
            'unit_cost' => $data['purchase_price'] ?? null,
            'currency' => Str::upper($data['supplier_currency'] ?? 'NOK'),
            'lead_time_days' => $data['supplier_lead_time_days'] ?? 0,
            'moq' => $data['supplier_moq'] ?? 1,
            'pack_size' => $data['supplier_pack_size'] ?? 1,
        ];
    }

    private function syncPrimarySupplier(Item $item, array $supplierData): void
    {
        if (! $supplierData['vendor_id']) {
            $item->itemVendors()->where('is_primary', true)->delete();

            return;
        }

        $item->itemVendors()->where('is_primary', true)->where('vendor_id', '!=', $supplierData['vendor_id'])->delete();

        ItemVendor::updateOrCreate(
            [
                'item_id' => $item->id,
                'vendor_id' => $supplierData['vendor_id'],
                'vendor_sku' => $supplierData['vendor_sku'],
            ],
            [
                'purchase_url' => $supplierData['purchase_url'],
                'currency' => $supplierData['currency'],
                'unit_cost' => $supplierData['unit_cost'],
                'moq' => $supplierData['moq'],
                'pack_size' => $supplierData['pack_size'],
                'lead_time_days' => $supplierData['lead_time_days'],
                'is_primary' => true,
            ]
        );
    }

    private function resolveVendor(?string $vendorId, bool $isManufacturer, bool $isSupplier): ?Vendor
    {
        if (! $vendorId) {
            return null;
        }

        $vendor = Vendor::find($vendorId);

        if ($vendor) {
            $vendor->forceFill([
                'is_manufacturer' => $vendor->is_manufacturer || $isManufacturer,
                'is_supplier' => $vendor->is_supplier || $isSupplier,
            ])->save();
        }

        return $vendor;
    }
}
