<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Ticket\Models\TicketCostEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class PickingListQuery
{
    public const SORTABLE_COLUMNS = [
        'item',
        'ticket',
        'client',
        'location',
        'reserved',
        'on_hand',
        'status',
    ];

    private const IDENTIFIED_UNIT_SQL = '(storage_items.has_serials = 1 '
        .'OR storage_items.track_batch = 1 '
        .'OR storage_items.expiry_enabled = 1 '
        .'OR EXISTS ('
        .'SELECT 1 FROM storage_stock_units '
        .'WHERE storage_stock_units.item_id = storage_items.id '
        .'AND storage_stock_units.current_qty > 0 '
        .'AND storage_stock_units.deleted_at IS NULL'
        .'))';

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
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $query = $this->baseQuery()
            ->when(($filters['status'] ?? '') === 'ready', function (Builder $query): void {
                $this->whereGenericallyPickable($query);
                $query->whereColumn('storage_items.qty_on_hand', '>=', 'ticket_cost_entries.quantity');
            })
            ->when(($filters['status'] ?? '') === 'waiting', function (Builder $query): void {
                $this->whereGenericallyPickable($query);
                $query->whereColumn('storage_items.qty_on_hand', '<', 'ticket_cost_entries.quantity');
            })
            ->when(
                ($filters['status'] ?? '') === 'identified',
                fn (Builder $query) => $this->whereIdentified($query)
            )
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $search = '%'.trim($search).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('ticket_cost_entries.item_name', 'like', $search)
                        ->orWhere('ticket_cost_entries.item_sku', 'like', $search)
                        ->orWhereHas('ticket', function (Builder $query) use ($search): void {
                            $query->where('ticket_key', 'like', $search)
                                ->orWhere('subject', 'like', $search);
                        });
                });
            });

        $this->applySorting($query, $filters);

        return $query
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Return only a valid explicit sort state while preserving the queue filters.
     */
    public function normalizeFilters(array $filters): array
    {
        $sort = (string) ($filters['sort'] ?? '');

        if (! in_array($sort, self::SORTABLE_COLUMNS, true)) {
            unset($filters['sort'], $filters['direction']);

            return $filters;
        }

        $filters['sort'] = $sort;
        $filters['direction'] = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $filters;
    }

    /**
     * Keep the existing operational default and use explicit allowlisted expressions on demand.
     */
    private function applySorting(Builder $query, array $filters): void
    {
        $sort = (string) ($filters['sort'] ?? '');

        if (! in_array($sort, self::SORTABLE_COLUMNS, true)) {
            $query
                ->orderByRaw('CASE WHEN storage_items.qty_on_hand >= ticket_cost_entries.quantity THEN 0 ELSE 1 END')
                ->orderBy('storage_items.sku')
                ->orderBy('ticket_cost_entries.created_at')
                ->orderBy('ticket_cost_entries.id');

            return;
        }

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        switch ($sort) {
            case 'item':
                $query->orderByRaw(
                    "COALESCE(NULLIF(ticket_cost_entries.item_sku, ''), storage_items.sku) ".$direction
                )
                    ->orderBy('ticket_cost_entries.item_name', $direction);
                break;
            case 'ticket':
                $this->joinTicketsForSorting($query);
                $query->orderBy('sort_tickets.ticket_key', $direction)
                    ->orderBy('sort_tickets.subject', $direction);
                break;
            case 'client':
                $this->joinTicketsForSorting($query);
                $query->leftJoin('clients as sort_clients', 'sort_tickets.client_id', '=', 'sort_clients.id')
                    ->orderByRaw('CASE WHEN sort_clients.name IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('sort_clients.name', $direction);
                break;
            case 'location':
                $query->leftJoin('storage_warehouses as sort_pick_warehouses', function (JoinClause $join): void {
                    $join->on('storage_items.warehouse_id', '=', 'sort_pick_warehouses.id')
                        ->whereNull('sort_pick_warehouses.deleted_at');
                })
                    ->leftJoin('storage_boxes as sort_pick_boxes', function (JoinClause $join): void {
                        $join->on('storage_items.box_id', '=', 'sort_pick_boxes.id')
                            ->whereNull('sort_pick_boxes.deleted_at');
                    })
                    ->orderByRaw('CASE WHEN sort_pick_warehouses.name IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('sort_pick_warehouses.name', $direction)
                    ->orderByRaw('CASE WHEN sort_pick_boxes.id IS NULL THEN 1 ELSE 0 END')
                    ->orderByRaw("CASE WHEN NULLIF(sort_pick_boxes.code_human, '') IS NULL THEN 1 ELSE 0 END")
                    ->orderBy('sort_pick_boxes.code_human', $direction)
                    ->orderBy('sort_pick_boxes.id', $direction);
                break;
            case 'reserved':
                $query->orderBy('ticket_cost_entries.quantity', $direction);
                break;
            case 'on_hand':
                $query->orderBy('storage_items.qty_on_hand', $direction);
                break;
            case 'status':
                $query->orderByRaw(
                    'CASE WHEN '.self::IDENTIFIED_UNIT_SQL.' THEN 2 '
                    .'WHEN storage_items.qty_on_hand >= ticket_cost_entries.quantity THEN 0 '
                    .'ELSE 1 END '.$direction
                );
                break;
        }

        $query->orderBy('ticket_cost_entries.id');
    }

    private function joinTicketsForSorting(Builder $query): void
    {
        $query->join('tickets as sort_tickets', function (JoinClause $join): void {
            $join->on('ticket_cost_entries.ticket_id', '=', 'sort_tickets.id')
                ->whereNull('sort_tickets.deleted_at');
        });
    }

    /*
     * Stats intentionally use the unsorted base query because ordering has no bearing on aggregate values.
     */
    public function stats(): array
    {
        $base = $this->baseQuery();

        $ready = clone $base;
        $this->whereGenericallyPickable($ready);
        $waiting = clone $base;
        $this->whereGenericallyPickable($waiting);
        $identified = clone $base;
        $this->whereIdentified($identified);

        return [
            'ready' => $ready
                ->whereColumn('storage_items.qty_on_hand', '>=', 'ticket_cost_entries.quantity')
                ->count(),
            'waiting' => $waiting
                ->whereColumn('storage_items.qty_on_hand', '<', 'ticket_cost_entries.quantity')
                ->count(),
            'identified' => $identified->count(),
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
                'storageItem' => function ($query): void {
                    $query
                        ->with(['box', 'warehouse'])
                        ->withCount([
                            'stockUnits as positive_stock_units_count' => fn (Builder $query) => $query
                                ->where('current_qty', '>', 0),
                        ]);
                },
                'ticket.client',
                'ticket.owner',
            ])
            ->where('ticket_cost_entries.status', 'reserved')
            ->whereNotNull('ticket_cost_entries.storage_item_id')
            ->whereHas('ticket');
    }

    private function whereGenericallyPickable(Builder $query): void
    {
        $query
            ->where('storage_items.has_serials', false)
            ->where('storage_items.track_batch', false)
            ->where('storage_items.expiry_enabled', false)
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('storage_stock_units')
                    ->whereColumn('storage_stock_units.item_id', 'storage_items.id')
                    ->where('storage_stock_units.current_qty', '>', 0)
                    ->whereNull('storage_stock_units.deleted_at');
            });
    }

    private function whereIdentified(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->where('storage_items.has_serials', true)
                ->orWhere('storage_items.track_batch', true)
                ->orWhere('storage_items.expiry_enabled', true)
                ->orWhereExists(function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from('storage_stock_units')
                        ->whereColumn('storage_stock_units.item_id', 'storage_items.id')
                        ->where('storage_stock_units.current_qty', '>', 0)
                        ->whereNull('storage_stock_units.deleted_at');
                });
        });
    }
}
