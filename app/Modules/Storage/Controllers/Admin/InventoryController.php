<?php

namespace App\Modules\Storage\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\StoreWarehouse;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryController extends Controller
{
    /**
     * Show storage inventory settings owned by administrators.
     */
    public function index(): View
    {
        return view('storage::Admin.Inventory.index', [
            'warehouses' => Warehouse::query()
                ->withCount(['items', 'boxes'])
                ->orderBy('name')
                ->get(),
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
}
