<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Ticket\Models\TicketCostEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PickingListQuery
{
    /*
    |--------------------------------------------------------------------------
    | Ticket reservation pick list
    |--------------------------------------------------------------------------
    |
    | Storage owns the operational picking view, while Ticket owns the cost
    | entry that created the reservation. The list keeps available reservations
    | first so technicians can work down the queue without opening each ticket.
    |
    */
    public function paginate(Request $request): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->when($request->string('status')->toString() === 'ready', function (Builder $query): void {
                $query->whereColumn('storage_items.qty_on_hand', '>=', 'ticket_cost_entries.quantity');
            })
            ->when($request->string('status')->toString() === 'waiting', function (Builder $query): void {
                $query->whereColumn('storage_items.qty_on_hand', '<', 'ticket_cost_entries.quantity');
            })
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $search = '%' . $request->string('q')->trim()->toString() . '%';

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('ticket_cost_entries.item_name', 'like', $search)
                        ->orWhere('ticket_cost_entries.item_sku', 'like', $search)
                        ->orWhereHas('ticket', function (Builder $query) use ($search): void {
                            $query->where('ticket_key', 'like', $search)
                                ->orWhere('subject', 'like', $search);
                        });
                });
            })
            ->orderByRaw('CASE WHEN storage_items.qty_on_hand >= ticket_cost_entries.quantity THEN 0 ELSE 1 END')
            ->orderBy('storage_items.sku')
            ->orderBy('ticket_cost_entries.created_at')
            ->paginate(25)
            ->withQueryString();
    }

    public function stats(): array
    {
        $base = $this->baseQuery();

        return [
            'ready' => (clone $base)->whereColumn('storage_items.qty_on_hand', '>=', 'ticket_cost_entries.quantity')->count(),
            'waiting' => (clone $base)->whereColumn('storage_items.qty_on_hand', '<', 'ticket_cost_entries.quantity')->count(),
            'reserved_quantity' => (clone $base)->sum('ticket_cost_entries.quantity'),
            'tickets' => (clone $base)->distinct('ticket_cost_entries.ticket_id')->count('ticket_cost_entries.ticket_id'),
        ];
    }

    private function baseQuery(): Builder
    {
        return TicketCostEntry::query()
            ->select('ticket_cost_entries.*')
            ->join('storage_items', 'storage_items.id', '=', 'ticket_cost_entries.storage_item_id')
            ->with([
                'reservation',
                'storageItem.box',
                'storageItem.warehouse',
                'ticket.client',
                'ticket.owner',
            ])
            ->where('ticket_cost_entries.status', 'reserved')
            ->whereNotNull('ticket_cost_entries.storage_item_id')
            ->whereHas('ticket');
    }
}
