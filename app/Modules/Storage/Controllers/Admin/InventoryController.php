<?php

namespace App\Modules\Storage\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\StoreWarehouse;
use App\Modules\Storage\Models\Warehouse;
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
    public function index(StorageInventoryDefaults $defaults): View
    {
        $defaultWarehouse = $defaults->defaultWarehouse();

        return view('storage::Admin.Inventory.index', [
            'warehouses' => Warehouse::query()
                ->withCount(['items', 'boxes'])
                ->orderBy('name')
                ->get(),
            'defaultWarehouse' => $defaultWarehouse,
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
            ->with('success', 'Warehouse ' . $warehouse->name . ' created.');
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
