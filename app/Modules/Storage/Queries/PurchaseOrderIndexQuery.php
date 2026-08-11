<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Storage\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class PurchaseOrderIndexQuery
{
    public const INDEX_SORTABLE_COLUMNS = [
        'order',
        'supplier',
        'status',
        'ordered',
        'expected',
        'progress',
        'shipments',
        'outstanding',
    ];

    public const RECEIVING_SORTABLE_COLUMNS = [
        'order',
        'supplier',
        'destination',
        'expected',
        'shipments',
        'received',
        'outstanding',
    ];

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $filters = $this->normalizeIndexFilters($filters);
        $query = $this->build($filters)
            ->with('supplierOrderImport:id,purchase_order_id');
        $this->applyIndexSorting($query, $filters);

        return $query
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateReceiving(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $filters = $this->normalizeReceivingFilters($filters);
        $query = $this->build($filters)
            ->whereIn('storage_purchase_orders.status', ['ordered', 'partially_received']);

        $this->applyReceivingSorting($query, $filters);

        return $query
            ->paginate($perPage)
            ->withQueryString();
    }

    public function normalizeIndexFilters(array $filters): array
    {
        return $this->normalizeSortFilters($filters, self::INDEX_SORTABLE_COLUMNS);
    }

    public function normalizeReceivingFilters(array $filters): array
    {
        return $this->normalizeSortFilters($filters, self::RECEIVING_SORTABLE_COLUMNS);
    }

    private function build(array $filters): Builder
    {
        return PurchaseOrder::query()
            ->select('storage_purchase_orders.*')
            ->with(['vendor', 'deliverToWarehouse'])
            ->withCount(['lines', 'shipments'])
            ->withSum('lines as qty_ordered_total', 'qty_ordered')
            ->withSum('lines as qty_received_total', 'qty_received')
            ->withSum('lines as qty_cancelled_total', 'qty_cancelled')
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status) => $query->where('storage_purchase_orders.status', $status)
            )
            ->when(
                $filters['vendor_id'] ?? null,
                fn (Builder $query, $vendorId) => $query->where('storage_purchase_orders.vendor_id', $vendorId)
            )
            ->when(
                $filters['warehouse_id'] ?? null,
                fn (Builder $query, $warehouseId) => $query
                    ->where('storage_purchase_orders.deliver_to_warehouse_id', $warehouseId)
            )
            ->when(
                $filters['expected_after'] ?? null,
                fn (Builder $query, string $date) => $query
                    ->whereDate('storage_purchase_orders.expected_at', '>=', $date)
            )
            ->when(
                $filters['expected_before'] ?? null,
                fn (Builder $query, string $date) => $query
                    ->whereDate('storage_purchase_orders.expected_at', '<=', $date)
            )
            ->when($filters['tracking_number'] ?? null, function (Builder $query, string $tracking): void {
                $query->whereHas('shipments.trackings', fn (Builder $trackingQuery) => $trackingQuery
                    ->where('tracking_number', 'like', '%'.$tracking.'%'));
            })
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('storage_purchase_orders.po_number', 'like', '%'.$search.'%')
                        ->orWhere('storage_purchase_orders.vendor_ref', 'like', '%'.$search.'%')
                        ->orWhereHas('vendor', fn (Builder $vendorQuery) => $vendorQuery
                            ->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('shipments.trackings', fn (Builder $trackingQuery) => $trackingQuery
                            ->where('tracking_number', 'like', '%'.$search.'%'));
                });
            });
    }

    private function applyIndexSorting(Builder $query, array $filters): void
    {
        $sort = (string) ($filters['sort'] ?? '');

        if (! in_array($sort, self::INDEX_SORTABLE_COLUMNS, true)) {
            $query->latest('storage_purchase_orders.updated_at')
                ->latest('storage_purchase_orders.id');

            return;
        }

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        switch ($sort) {
            case 'order':
                $query->orderBy('storage_purchase_orders.po_number', $direction);
                break;
            case 'supplier':
                $query->leftJoin(
                    'vendors as sort_vendors',
                    'storage_purchase_orders.vendor_id',
                    '=',
                    'sort_vendors.id'
                )
                    ->orderByRaw(
                        "CASE WHEN COALESCE(NULLIF(storage_purchase_orders.supplier_name_snapshot, ''), "
                        .'sort_vendors.name) IS NULL THEN 1 ELSE 0 END'
                    )
                    ->orderByRaw(
                        "COALESCE(NULLIF(storage_purchase_orders.supplier_name_snapshot, ''), "
                        .'sort_vendors.name) '.$direction
                    );
                break;
            case 'status':
                $query->orderByRaw(
                    "case storage_purchase_orders.status
                        when 'draft' then 1
                        when 'ordered' then 2
                        when 'partially_received' then 3
                        when 'received' then 4
                        when 'closed' then 5
                        when 'cancelled' then 6
                        else 99 end ".$direction
                );
                break;
            case 'ordered':
                $query->orderByRaw(
                    'CASE WHEN storage_purchase_orders.ordered_at IS NULL THEN 1 ELSE 0 END'
                )
                    ->orderBy('storage_purchase_orders.ordered_at', $direction);
                break;
            case 'expected':
                $query->orderByRaw(
                    'CASE WHEN storage_purchase_orders.expected_at IS NULL THEN 1 ELSE 0 END'
                )
                    ->orderBy('storage_purchase_orders.expected_at', $direction);
                break;
            case 'progress':
                $query->orderByRaw(
                    'case '
                    .'when coalesce(qty_ordered_total, 0) <= 0 then 0 '
                    .'when coalesce(qty_received_total, 0) >= qty_ordered_total then 1 '
                    .'else (coalesce(qty_received_total, 0) * 1.0) / qty_ordered_total '
                    .'end '.$direction
                );
                break;
            case 'shipments':
                $query->orderBy('shipments_count', $direction);
                break;
            case 'outstanding':
                $outstanding = '(coalesce(qty_ordered_total, 0) '
                    .'- coalesce(qty_received_total, 0) '
                    .'- coalesce(qty_cancelled_total, 0))';
                $query->orderByRaw(
                    'case when '.$outstanding.' > 0 then '.$outstanding.' else 0 end '.$direction
                );
                break;
        }

        $query->orderBy('storage_purchase_orders.po_number')
            ->orderBy('storage_purchase_orders.id');
    }

    private function applyReceivingSorting(Builder $query, array $filters): void
    {
        $sort = (string) ($filters['sort'] ?? '');

        if (! in_array($sort, self::RECEIVING_SORTABLE_COLUMNS, true)) {
            $query->orderByRaw(
                'CASE WHEN storage_purchase_orders.expected_at IS NULL THEN 1 ELSE 0 END'
            )
                ->orderBy('storage_purchase_orders.expected_at')
                ->orderBy('storage_purchase_orders.po_number')
                ->orderBy('storage_purchase_orders.id');

            return;
        }

        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        switch ($sort) {
            case 'order':
                $query->orderBy('storage_purchase_orders.po_number', $direction);
                break;
            case 'supplier':
                $query->leftJoin(
                    'vendors as sort_receiving_vendors',
                    'storage_purchase_orders.vendor_id',
                    '=',
                    'sort_receiving_vendors.id'
                )
                    ->orderByRaw(
                        "CASE WHEN COALESCE(NULLIF(storage_purchase_orders.supplier_name_snapshot, ''), "
                        .'sort_receiving_vendors.name) IS NULL THEN 1 ELSE 0 END'
                    )
                    ->orderByRaw(
                        "COALESCE(NULLIF(storage_purchase_orders.supplier_name_snapshot, ''), "
                        .'sort_receiving_vendors.name) '.$direction
                    );
                break;
            case 'destination':
                $query->leftJoin(
                    'storage_warehouses as sort_receiving_warehouses',
                    function (JoinClause $join): void {
                        $join->on(
                            'storage_purchase_orders.deliver_to_warehouse_id',
                            '=',
                            'sort_receiving_warehouses.id'
                        )->whereNull('sort_receiving_warehouses.deleted_at');
                    }
                )
                    ->orderByRaw(
                        'CASE WHEN sort_receiving_warehouses.name IS NULL THEN 1 ELSE 0 END'
                    )
                    ->orderBy('sort_receiving_warehouses.name', $direction);
                break;
            case 'expected':
                $query->orderByRaw(
                    'CASE WHEN storage_purchase_orders.expected_at IS NULL THEN 1 ELSE 0 END'
                )
                    ->orderBy('storage_purchase_orders.expected_at', $direction);
                break;
            case 'shipments':
                $query->orderBy('shipments_count', $direction);
                break;
            case 'received':
                $query->orderByRaw('COALESCE(qty_received_total, 0) '.$direction);
                break;
            case 'outstanding':
                $outstanding = '(COALESCE(qty_ordered_total, 0) '
                    .'- COALESCE(qty_received_total, 0) '
                    .'- COALESCE(qty_cancelled_total, 0))';
                $query->orderByRaw(
                    'CASE WHEN '.$outstanding.' > 0 THEN '.$outstanding.' ELSE 0 END '.$direction
                );
                break;
        }

        $query->orderBy('storage_purchase_orders.po_number')
            ->orderBy('storage_purchase_orders.id');
    }

    private function normalizeSortFilters(array $filters, array $sortableColumns): array
    {
        $sort = (string) ($filters['sort'] ?? '');

        if (! in_array($sort, $sortableColumns, true)) {
            unset($filters['sort'], $filters['direction']);

            return $filters;
        }

        $filters['sort'] = $sort;
        $filters['direction'] = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $filters;
    }
}
