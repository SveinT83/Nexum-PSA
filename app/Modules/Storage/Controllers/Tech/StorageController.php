<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\StoreWarehouse;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Queries\PickingListQuery;
use App\Modules\Storage\Queries\StorageIndexQuery;
use App\Modules\Ticket\Actions\PickTicketStorageReservation;
use App\Modules\Ticket\Models\TicketCostEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorageController extends Controller
{
    public function index(Request $request, StorageIndexQuery $query): View
    {
        $filters = $request->only(['availability', 'q', 'warehouse_id']);

        return view('storage::Tech.Storage.index', [
            'items' => $query->paginate($filters),
            'warehouses' => Warehouse::orderBy('name')->get(),
            'boxes' => Box::with('warehouse')->orderBy('id')->get(),
            'stats' => [
                'total_items' => Item::count(),
                'out_of_stock' => Item::where('qty_on_hand', '<=', 0)->count(),
                'should_order' => Item::query()
                    ->where('should_order', true)
                    ->orWhere('qty_on_hand', '<=', 0)
                    ->orWhereColumn('qty_reserved', '>=', 'qty_on_hand')
                    ->orWhereColumn('qty_on_hand', '<=', 'reorder_point')
                    ->count(),
                'reserved' => Item::sum('qty_reserved'),
            ],
            'filters' => $filters,
        ]);
    }

    public function storeWarehouse(Request $request, StoreWarehouse $storeWarehouse): RedirectResponse
    {
        $warehouse = $storeWarehouse->handle($request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:storage_warehouses,code',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]));

        return redirect()->route('tech.storage.index')
            ->with('success', 'Warehouse ' . $warehouse->name . ' created.');
    }

    public function picking(Request $request, PickingListQuery $query): View
    {
        return view('storage::Tech.Storage.picking', [
            'reservations' => $query->paginate($request),
            'stats' => $query->stats(),
            'filters' => $request->only(['status', 'q']),
        ]);
    }

    public function pick(Request $request, TicketCostEntry $costEntry, PickTicketStorageReservation $pickReservation): RedirectResponse
    {
        $costEntry->loadMissing('ticket');

        abort_unless($costEntry->ticket, 404);

        try {
            $pickReservation->handle($costEntry->ticket, $costEntry, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['pick' => $exception->getMessage()]);
        }

        return redirect()->route('tech.storage.picking')
            ->with('success', 'Reserved item picked.');
    }
}
