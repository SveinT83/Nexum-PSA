<?php

namespace App\Modules\Storage\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\StoreWarehouse;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\CollectionTableSorter;
use App\Modules\Storage\Support\StorageInventoryDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    /**
     * Show storage inventory settings owned by administrators.
     */
    public function index(
        Request $request,
        StorageInventoryDefaults $defaults,
        CollectionTableSorter $sorter
    ): View {
        $defaultWarehouse = $defaults->defaultWarehouse();
        $columns = [
            'default' => [
                'type' => CollectionTableSorter::TYPE_BOOLEAN,
                'value' => fn (Warehouse $warehouse): bool => $defaultWarehouse?->is($warehouse) ?? false,
            ],
            'name' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (Warehouse $warehouse): string => $warehouse->name,
            ],
            'code' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (Warehouse $warehouse): ?string => $warehouse->code,
            ],
            'address' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (Warehouse $warehouse): ?string => $warehouse->address,
            ],
            'items' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (Warehouse $warehouse): int => (int) $warehouse->items_count,
            ],
            'boxes' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (Warehouse $warehouse): int => (int) $warehouse->boxes_count,
            ],
            'status' => [
                'type' => CollectionTableSorter::TYPE_BOOLEAN,
                'value' => fn (Warehouse $warehouse): bool => $warehouse->is_active,
            ],
        ];
        $warehouseSort = $sorter->normalizeColumn($request->query('warehouse_sort'), $columns);
        $warehouseDirection = $sorter->normalizeDirection(
            $request->query('warehouse_direction'),
            in_array($warehouseSort, ['default', 'status'], true) ? 'desc' : 'asc'
        );
        $warehouses = Warehouse::query()
            ->withCount(['items', 'boxes'])
            ->orderBy('name')
            ->get();
        $warehouses = $sorter->sort($warehouses, $warehouseSort, $warehouseDirection, $columns);
        $warehouseSortQuery = $warehouseSort === null ? [] : [
            'warehouse_sort' => $warehouseSort,
            'warehouse_direction' => $warehouseDirection,
        ];

        return view('storage::Admin.Inventory.index', [
            'warehouses' => $warehouses,
            'defaultWarehouse' => $defaultWarehouse,
            'warehouseSort' => $warehouseSort,
            'warehouseDirection' => $warehouseDirection,
            'warehouseSortQuery' => $warehouseSortQuery,
        ]);
    }

    /**
     * Create a warehouse that can be used by inventory items and boxes.
     */
    public function storeWarehouse(Request $request, StoreWarehouse $storeWarehouse): RedirectResponse
    {
        $warehouse = $storeWarehouse->handle($request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:storage_warehouses,code',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]));

        return redirect()->route('tech.admin.settings.storage.inventory')
            ->with('success', 'Warehouse '.$warehouse->name.' created.');
    }

    public function updateDefaultWarehouse(Request $request, StorageInventoryDefaults $defaults): RedirectResponse
    {
        $data = $request->validate([
            'default_warehouse_id' => [
                'required',
                Rule::exists('storage_warehouses', 'id')->where('is_active', true),
            ],
        ]);

        $warehouse = Warehouse::query()->whereKey($data['default_warehouse_id'])->firstOrFail();
        $defaults->setDefaultWarehouse($warehouse);

        return redirect()->route('tech.admin.settings.storage.inventory')
            ->with('success', 'Default warehouse updated.');
    }
}
