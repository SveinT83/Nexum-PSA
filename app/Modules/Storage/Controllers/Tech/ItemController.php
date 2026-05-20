<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\AdjustItemStock;
use App\Modules\Storage\Actions\StoreItem;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class ItemController extends Controller
{
    public function create(): View
    {
        return view('storage::Tech.Storage.items.create', [
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'boxes' => Box::where('is_active', true)->with('warehouse')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, StoreItem $storeItem): RedirectResponse
    {
        $item = $storeItem->handle($this->validatedItem($request), $request->user());

        return redirect()->route('tech.storage.items.show', $item)
            ->with('success', 'Storage item created.');
    }

    public function show(Item $item): View
    {
        $item->load(['warehouse', 'box', 'movements.actor']);

        return view('storage::Tech.Storage.items.show', compact('item'));
    }

    public function edit(Item $item): View
    {
        return view('storage::Tech.Storage.items.edit', [
            'item' => $item,
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(),
            'boxes' => Box::where('is_active', true)->with('warehouse')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => 'required|exists:storage_warehouses,id',
            'box_id' => 'nullable|exists:storage_boxes,id',
            'sku' => 'required|string|max:100|unique:storage_items,sku,' . $item->id,
            'name' => 'required|string|max:255',
            'short_description' => 'nullable|string',
            'long_description' => 'nullable|string',
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

        $data['sku'] = strtoupper($data['sku']);
        $data['box_id'] = $data['box_id'] ?: null;
        $data['has_serials'] = $request->boolean('has_serials');
        $data['should_order'] = $request->boolean('should_order');
        $data['updated_by'] = $request->user()?->id;

        $item->update($data);

        return redirect()->route('tech.storage.items.show', $item)
            ->with('success', 'Storage item updated.');
    }

    public function adjust(Request $request, Item $item, AdjustItemStock $adjustItemStock): RedirectResponse
    {
        $data = $request->validate([
            'delta' => 'required|integer|not_in:0',
            'reason' => 'required|string|max:100',
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $adjustItemStock->handle($item, (int) $data['delta'], $data['reason'], $data['note'] ?? null, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['delta' => $exception->getMessage()]);
        }

        return back()->with('success', 'Stock adjusted.');
    }

    private function validatedItem(Request $request): array
    {
        return $request->validate([
            'warehouse_id' => 'required|exists:storage_warehouses,id',
            'box_id' => 'nullable|exists:storage_boxes,id',
            'sku' => 'required|string|max:100|unique:storage_items,sku',
            'name' => 'required|string|max:255',
            'short_description' => 'nullable|string',
            'long_description' => 'nullable|string',
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
}
