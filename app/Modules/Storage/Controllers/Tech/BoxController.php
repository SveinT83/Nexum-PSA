<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\StoreBox;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\CollectionTableSorter;
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

    public function show(Request $request, Box $box, CollectionTableSorter $sorter): View
    {
        $box->load(['warehouse', 'items', 'events.actor']);
        $itemColumns = [
            'sku' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (mixed $item): string => $item->sku,
            ],
            'name' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (mixed $item): string => $item->name,
            ],
            'on_hand' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (mixed $item): int => (int) $item->qty_on_hand,
            ],
            'reserved' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (mixed $item): int => (int) $item->qty_reserved,
            ],
        ];
        $eventColumns = [
            'when' => [
                'type' => CollectionTableSorter::TYPE_DATE,
                'value' => fn (mixed $event) => $event->created_at,
            ],
            'type' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (mixed $event): string => $event->type,
            ],
            'actor' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (mixed $event): string => $event->actor?->name ?? 'System',
            ],
        ];
        $boxItemSort = $sorter->normalizeColumn($request->query('box_item_sort'), $itemColumns);
        $boxItemDirection = $sorter->normalizeDirection($request->query('box_item_direction'));
        $boxEventSort = $sorter->normalizeColumn($request->query('box_event_sort'), $eventColumns);
        $boxEventDirection = $sorter->normalizeDirection(
            $request->query('box_event_direction'),
            $boxEventSort === 'when' ? 'desc' : 'asc'
        );
        $boxItems = $sorter->sort($box->items, $boxItemSort, $boxItemDirection, $itemColumns);
        $boxEvents = $box->events->sortByDesc('created_at')->values();
        $boxEvents = $sorter->sort($boxEvents, $boxEventSort, $boxEventDirection, $eventColumns);
        $boxSortQuery = array_filter([
            'box_item_sort' => $boxItemSort,
            'box_item_direction' => $boxItemSort === null ? null : $boxItemDirection,
            'box_event_sort' => $boxEventSort,
            'box_event_direction' => $boxEventSort === null ? null : $boxEventDirection,
        ], fn (mixed $value): bool => $value !== null);

        return view('storage::Tech.Storage.boxes.show', compact(
            'box',
            'boxItems',
            'boxEvents',
            'boxItemSort',
            'boxItemDirection',
            'boxEventSort',
            'boxEventDirection',
            'boxSortQuery'
        ));
    }
}
