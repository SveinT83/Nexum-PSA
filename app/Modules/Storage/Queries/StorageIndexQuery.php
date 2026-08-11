<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class StorageIndexQuery
{
    public const SORTABLE_COLUMNS = [
        'item',
        'warehouse',
        'supplier',
        'box',
        'on_hand',
        'reserved',
        'available',
        'incoming',
        'status',
    ];

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $filters = $this->normalizeFilters($filters);
        $query = Item::query()
            ->select('storage_items.*')
            ->addSelect(['qty_incoming' => $this->incomingQuantitySubquery()])
            ->with(['warehouse', 'box', 'primaryVendor'])
            ->when(($filters['availability'] ?? 'should_order') === 'should_order', fn (Builder $query) => $this->applyShouldOrder($query))
            ->when(($filters['availability'] ?? null) === 'all', fn ($query) => $query)
            ->when(($filters['availability'] ?? null) === 'in_stock', fn ($query) => $query->where('storage_items.qty_on_hand', '>', 0))
            ->when(($filters['availability'] ?? null) === 'out_of_stock', fn ($query) => $query->where('storage_items.qty_on_hand', '<=', 0))
            ->when($filters['q'] ?? null, function ($query, string $search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('storage_items.sku', 'like', '%'.$search.'%')
                        ->orWhere('storage_items.name', 'like', '%'.$search.'%')
                        ->orWhere('storage_items.ean_number', 'like', '%'.$search.'%');
                });
            })
            ->when(
                $filters['warehouse_id'] ?? null,
                fn ($query, $warehouseId) => $query->where('storage_items.warehouse_id', $warehouseId)
            )
            ->when(
                $filters['supplier_id'] ?? null,
                fn ($query, $supplierId) => $query->where('storage_items.primary_vendor_id', $supplierId)
            );

        $this->applySorting($query, $filters);

        return $query
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Return only a valid explicit sort state while preserving the other controller-owned filters.
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
     * Count the same reorder pressure shown by the Storage index.
     */
    public function shouldOrderCount(): int
    {
        return $this->applyShouldOrder(Item::query())->count();
    }

    /**
     * Keep the manual, empty-stock, over-reserved, and reorder-point rules in Storage ownership.
     */
    private function applyShouldOrder(Builder $query): Builder
    {
        return $query->where(function (Builder $nested): void {
            $nested->where('storage_items.should_order', true)
                ->orWhere('storage_items.qty_on_hand', '<=', 0)
                ->orWhereColumn('storage_items.qty_reserved', '>=', 'storage_items.qty_on_hand')
                ->orWhere(function (Builder $reorderPoint): void {
                    $reorderPoint->where('storage_items.reorder_point', '>', 0)
                        ->whereColumn('storage_items.qty_on_hand', '<=', 'storage_items.reorder_point');
                });
        });
    }

    /**
     * Sum only the positive quantity still expected on active, placed Purchase Orders.
     */
    private function incomingQuantitySubquery(): Builder
    {
        return PurchaseOrderLine::query()
            ->selectRaw(
                'COALESCE(SUM(CASE '
                .'WHEN storage_purchase_order_lines.qty_ordered '
                .'> storage_purchase_order_lines.qty_received + storage_purchase_order_lines.qty_cancelled '
                .'THEN storage_purchase_order_lines.qty_ordered '
                .'- storage_purchase_order_lines.qty_received '
                .'- storage_purchase_order_lines.qty_cancelled '
                .'ELSE 0 END), 0)'
            )
            ->join(
                'storage_purchase_orders as incoming_purchase_orders',
                'incoming_purchase_orders.id',
                '=',
                'storage_purchase_order_lines.purchase_order_id'
            )
            ->whereColumn('storage_purchase_order_lines.item_id', 'storage_items.id')
            ->whereIn('incoming_purchase_orders.status', [
                PurchaseOrder::STATUS_ORDERED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->whereNull('incoming_purchase_orders.deleted_at');
    }

    /**
     * Keep every accepted key mapped to a fixed SQL expression and use the item id as a stable tie-breaker.
     */
    private function applySorting(Builder $query, array $filters): void
    {
        $sort = (string) ($filters['sort'] ?? '');

        if (! in_array($sort, self::SORTABLE_COLUMNS, true)) {
            $query->latest('storage_items.updated_at')
                ->latest('storage_items.id');

            return;
        }

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        switch ($sort) {
            case 'item':
                $query->orderBy('storage_items.sku', $direction)
                    ->orderBy('storage_items.name', $direction);
                break;
            case 'warehouse':
                $query->leftJoin('storage_warehouses as sort_warehouses', function (JoinClause $join): void {
                    $join->on('storage_items.warehouse_id', '=', 'sort_warehouses.id')
                        ->whereNull('sort_warehouses.deleted_at');
                })
                    ->orderByRaw('CASE WHEN sort_warehouses.name IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('sort_warehouses.name', $direction);
                break;
            case 'supplier':
                $query->leftJoin('vendors as sort_item_vendors', function (JoinClause $join): void {
                    $join->on('storage_items.primary_vendor_id', '=', 'sort_item_vendors.id');
                })
                    ->orderByRaw('CASE WHEN sort_item_vendors.name IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('sort_item_vendors.name', $direction);
                break;
            case 'box':
                $query->leftJoin('storage_boxes as sort_boxes', function (JoinClause $join): void {
                    $join->on('storage_items.box_id', '=', 'sort_boxes.id')
                        ->whereNull('sort_boxes.deleted_at');
                })
                    ->orderByRaw('CASE WHEN sort_boxes.id IS NULL THEN 1 ELSE 0 END')
                    ->orderByRaw("CASE WHEN NULLIF(sort_boxes.code_human, '') IS NULL THEN 1 ELSE 0 END")
                    ->orderBy('sort_boxes.code_human', $direction)
                    ->orderBy('sort_boxes.id', $direction);
                break;
            case 'on_hand':
                $query->orderBy('storage_items.qty_on_hand', $direction);
                break;
            case 'reserved':
                $query->orderBy('storage_items.qty_reserved', $direction);
                break;
            case 'available':
                $available = '(storage_items.qty_on_hand - storage_items.qty_reserved)';
                $query->orderByRaw(
                    'CASE WHEN '.$available.' > 0 THEN '.$available.' ELSE 0 END '.$direction
                );
                break;
            case 'incoming':
                $query->orderBy('qty_incoming', $direction);
                break;
            case 'status':
                $query->orderByRaw(
                    'CASE WHEN storage_items.should_order = 1 '
                    .'OR storage_items.qty_on_hand <= 0 '
                    .'OR storage_items.qty_reserved >= storage_items.qty_on_hand '
                    .'OR (storage_items.reorder_point > 0 '
                    .'AND storage_items.qty_on_hand <= storage_items.reorder_point) '
                    .'THEN 0 ELSE 1 END '.$direction
                );
                break;
        }

        $query->orderBy('storage_items.id');
    }
}
