<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierOrderWorkspaceQuery
{
    public const SCOPES = [
        'all' => 'All',
        'attention' => 'Needs attention',
        'incoming' => 'Incoming',
        'orders' => 'Purchase Orders',
        'receiving' => 'Receiving',
        'completed' => 'Completed',
    ];

    private const SORTS = [
        'order',
        'supplier',
        'status',
        'expected',
        'progress',
        'outstanding',
        'updated',
        'destination',
        'shipments',
        'received',
    ];

    /** @return array<string, mixed> */
    public function viewData(
        Request $request,
        bool $includePurchaseOrders,
        bool $includeImports,
        bool $receivingOnly = false,
    ): array {
        $filters = $this->normalizeFilters($request->only([
            'q',
            'scope',
            'status',
            'stage',
            'vendor_id',
            'warehouse_id',
            'expected_after',
            'expected_before',
            'tracking_number',
            'method',
            'sort',
            'direction',
            'per_page',
        ]));

        if ($receivingOnly) {
            $filters['scope'] = 'receiving';
        }

        return [
            'supplierOrders' => $this->paginate(
                $filters,
                $includePurchaseOrders,
                $includeImports,
                $receivingOnly,
            ),
            'suppliers' => Vendor::query()
                ->where('is_supplier', true)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'warehouses' => Warehouse::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'statuses' => array_values(array_unique(array_merge(
                PurchaseOrder::statuses(),
                PurchaseOrderImport::statuses(),
            ))),
            'stages' => PurchaseOrderImport::stages(),
            'scopes' => self::SCOPES,
            'filters' => $filters,
            'canViewPurchaseOrders' => $request->user()->can('storage.purchase_view'),
            'canViewImports' => $request->user()->can('storage.purchase_import_view'),
            'canReceive' => $request->user()->can('storage.purchase_receive'),
            'canRegisterPurchaseOrders' => $request->user()->can('storage.purchase_manage'),
        ];
    }

    public function paginate(
        array $filters,
        bool $includePurchaseOrders,
        bool $includeImports,
        bool $receivingOnly = false,
    ): LengthAwarePaginator {
        $filters = $this->normalizeFilters($filters);
        $branches = [];

        if ($includePurchaseOrders) {
            $branches[] = $this->purchaseOrderBranch($receivingOnly);
        }

        if ($includeImports) {
            $branches[] = $this->importBranch(
                deduplicateAgainstPurchaseOrders: $includePurchaseOrders && ! $receivingOnly,
            );
        }

        if ($branches === []) {
            $branches[] = $this->emptyBranch();
        }

        $union = array_shift($branches);
        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        $query = DB::query()->fromSub($union, 'supplier_order_rows');
        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        return $query
            ->paginate($filters['per_page'])
            ->withQueryString();
    }

    /** @return array<string, mixed> */
    public function normalizeFilters(array $filters): array
    {
        $scope = (string) ($filters['scope'] ?? 'all');
        $filters['scope'] = array_key_exists($scope, self::SCOPES) ? $scope : 'all';

        $status = trim((string) ($filters['status'] ?? ''));
        $allowedStatuses = array_merge(PurchaseOrder::statuses(), PurchaseOrderImport::statuses());
        $filters['status'] = in_array($status, $allowedStatuses, true) ? $status : null;

        $stage = trim((string) ($filters['stage'] ?? ''));
        $filters['stage'] = in_array($stage, PurchaseOrderImport::stages(), true) ? $stage : null;

        foreach (['vendor_id', 'warehouse_id'] as $key) {
            $value = filter_var($filters[$key] ?? null, FILTER_VALIDATE_INT);
            $filters[$key] = $value && $value > 0 ? (int) $value : null;
        }

        foreach (['expected_after', 'expected_before'] as $key) {
            $filters[$key] = $this->normalizeDate($filters[$key] ?? null);
        }

        foreach (['q', 'tracking_number', 'method'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            $filters[$key] = $value === '' ? null : mb_substr($value, 0, 255);
        }

        $sort = (string) ($filters['sort'] ?? '');
        $filters['sort'] = in_array($sort, self::SORTS, true) ? $sort : null;
        $filters['direction'] = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) ($filters['per_page'] ?? 25);
        $filters['per_page'] = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        return $filters;
    }

    private function purchaseOrderBranch(bool $receivingOnly): Builder
    {
        $lineTotals = DB::table('storage_purchase_order_lines')
            ->select('purchase_order_id')
            ->selectRaw('COUNT(*) as lines_count')
            ->selectRaw('COALESCE(SUM(qty_ordered), 0) as qty_ordered')
            ->selectRaw('COALESCE(SUM(qty_received), 0) as qty_received')
            ->selectRaw('COALESCE(SUM(qty_cancelled), 0) as qty_cancelled')
            ->groupBy('purchase_order_id');

        $shipmentTotals = DB::table('storage_purchase_shipments')
            ->select('purchase_order_id')
            ->selectRaw('COUNT(*) as shipments_count')
            ->groupBy('purchase_order_id');

        $latestImportIds = DB::table('storage_purchase_order_imports')
            ->select('purchase_order_id')
            ->selectRaw('MAX(id) as latest_import_id')
            ->selectRaw('COUNT(*) as linked_import_count')
            ->whereNotNull('purchase_order_id')
            ->groupBy('purchase_order_id');

        $outstanding = '(COALESCE(line_totals.qty_ordered, 0) '
            .'- COALESCE(line_totals.qty_received, 0) '
            .'- COALESCE(line_totals.qty_cancelled, 0))';

        $query = DB::table('storage_purchase_orders as purchase_orders')
            ->leftJoin('vendors as suppliers', 'purchase_orders.vendor_id', '=', 'suppliers.id')
            ->leftJoin(
                'storage_warehouses as warehouses',
                function (JoinClause $join): void {
                    $join
                        ->on('purchase_orders.deliver_to_warehouse_id', '=', 'warehouses.id')
                        ->whereNull('warehouses.deleted_at');
                },
            )
            ->leftJoinSub($lineTotals, 'line_totals', function ($join): void {
                $join->on('purchase_orders.id', '=', 'line_totals.purchase_order_id');
            })
            ->leftJoinSub($shipmentTotals, 'shipment_totals', function ($join): void {
                $join->on('purchase_orders.id', '=', 'shipment_totals.purchase_order_id');
            })
            ->leftJoinSub($latestImportIds, 'import_summary', function ($join): void {
                $join->on('purchase_orders.id', '=', 'import_summary.purchase_order_id');
            })
            ->leftJoin(
                'storage_purchase_order_imports as latest_import',
                'latest_import.id',
                '=',
                'import_summary.latest_import_id',
            )
            ->whereNull('purchase_orders.deleted_at')
            ->selectRaw("'purchase_order' as row_type")
            ->addSelect([
                'purchase_orders.id as row_id',
                'purchase_orders.id as purchase_order_id',
                'latest_import.id as import_id',
            ])
            ->selectRaw('COALESCE(import_summary.linked_import_count, 0) as linked_import_count')
            ->addSelect([
                'purchase_orders.vendor_id as vendor_id',
            ])
            ->selectRaw(
                "COALESCE(NULLIF(purchase_orders.supplier_name_snapshot, ''), suppliers.name) "
                .'as supplier_name',
            )
            ->addSelect([
                'purchase_orders.deliver_to_warehouse_id as destination_warehouse_id',
                'warehouses.name as destination_name',
                'purchase_orders.po_number as order_number',
                'purchase_orders.vendor_ref as supplier_order_number',
                'purchase_orders.status as status',
            ])
            ->selectRaw(
                "CASE WHEN purchase_orders.status IN ('ordered', 'partially_received') "
                ."AND {$outstanding} > 0 THEN 'receiving' ELSE 'purchase_order' END as stage",
            )
            ->addSelect([
                'latest_import.extraction_method as import_method',
                'latest_import.reason_code as reason_code',
                'latest_import.source_type as source_type',
                'purchase_orders.metadata as purchase_order_metadata',
                'purchase_orders.ordered_at as ordered_at',
                'purchase_orders.expected_at as expected_at',
            ])
            ->selectRaw('COALESCE(line_totals.qty_ordered, 0) as qty_ordered')
            ->selectRaw('COALESCE(line_totals.qty_received, 0) as qty_received')
            ->selectRaw('COALESCE(line_totals.qty_cancelled, 0) as qty_cancelled')
            ->selectRaw("CASE WHEN {$outstanding} > 0 THEN {$outstanding} ELSE 0 END as qty_outstanding")
            ->selectRaw('COALESCE(shipment_totals.shipments_count, 0) as shipments_count')
            ->addSelect([
                'latest_import.attempt_count as attempt_count',
                'latest_import.next_retry_at as next_retry_at',
                'purchase_orders.updated_at as updated_at',
            ])
            ->selectRaw(
                "CASE WHEN purchase_orders.status IN ('ordered', 'partially_received') "
                ."AND {$outstanding} > 0 THEN 1 ELSE 0 END as is_receivable",
            )
            ->selectRaw('NULL as profile_name')
            ->selectSub(
                DB::table('storage_purchase_shipment_trackings as tracking')
                    ->join(
                        'storage_purchase_shipments as tracking_shipment',
                        'tracking.purchase_shipment_id',
                        '=',
                        'tracking_shipment.id',
                    )
                    ->whereColumn(
                        'tracking_shipment.purchase_order_id',
                        'purchase_orders.id',
                    )
                    ->selectRaw('GROUP_CONCAT(tracking.tracking_number)'),
                'tracking_numbers',
            );

        if ($receivingOnly) {
            $query
                ->whereIn('purchase_orders.status', [
                    PurchaseOrder::STATUS_ORDERED,
                    PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                ])
                ->whereRaw($outstanding.' > 0');
        }

        return $query;
    }

    private function importBranch(bool $deduplicateAgainstPurchaseOrders): Builder
    {
        $query = DB::table('storage_purchase_order_imports as imports')
            ->leftJoin('vendors as suppliers', 'imports.vendor_id', '=', 'suppliers.id')
            ->leftJoin(
                'storage_purchase_order_import_profiles as profiles',
                'imports.profile_id',
                '=',
                'profiles.id',
            )
            ->leftJoin(
                'storage_purchase_orders as linked_purchase_order',
                'imports.purchase_order_id',
                '=',
                'linked_purchase_order.id',
            )
            ->selectRaw("'import' as row_type")
            ->addSelect([
                'imports.id as row_id',
                'imports.purchase_order_id as purchase_order_id',
                'imports.id as import_id',
            ])
            ->selectRaw('1 as linked_import_count')
            ->addSelect([
                'imports.vendor_id as vendor_id',
                'suppliers.name as supplier_name',
            ])
            ->selectRaw('NULL as destination_warehouse_id')
            ->selectRaw('NULL as destination_name')
            ->selectRaw('NULL as order_number')
            ->addSelect([
                'imports.external_order_number as supplier_order_number',
                'imports.status as status',
                'imports.stage as stage',
                'imports.extraction_method as import_method',
                'imports.reason_code as reason_code',
                'imports.source_type as source_type',
            ])
            ->selectRaw('NULL as purchase_order_metadata')
            ->selectRaw('NULL as ordered_at')
            ->selectRaw('NULL as expected_at')
            ->selectRaw('0 as qty_ordered')
            ->selectRaw('0 as qty_received')
            ->selectRaw('0 as qty_cancelled')
            ->selectRaw('0 as qty_outstanding')
            ->selectRaw('0 as shipments_count')
            ->addSelect([
                'imports.attempt_count as attempt_count',
                'imports.next_retry_at as next_retry_at',
                'imports.updated_at as updated_at',
            ])
            ->selectRaw('0 as is_receivable')
            ->addSelect([
                'profiles.name as profile_name',
            ])
            ->selectRaw('NULL as tracking_numbers');

        if ($deduplicateAgainstPurchaseOrders) {
            $query->where(function (Builder $linked): void {
                $linked
                    ->whereNull('imports.purchase_order_id')
                    ->orWhereNotNull('linked_purchase_order.deleted_at');
            });
        }

        return $query;
    }

    private function emptyBranch(): Builder
    {
        return DB::table('storage_purchase_orders')
            ->whereRaw('1 = 0')
            ->selectRaw("'purchase_order' as row_type")
            ->selectRaw('0 as row_id')
            ->selectRaw('NULL as purchase_order_id')
            ->selectRaw('NULL as import_id')
            ->selectRaw('0 as linked_import_count')
            ->selectRaw('NULL as vendor_id')
            ->selectRaw('NULL as supplier_name')
            ->selectRaw('NULL as destination_warehouse_id')
            ->selectRaw('NULL as destination_name')
            ->selectRaw('NULL as order_number')
            ->selectRaw('NULL as supplier_order_number')
            ->selectRaw('NULL as status')
            ->selectRaw('NULL as stage')
            ->selectRaw('NULL as import_method')
            ->selectRaw('NULL as reason_code')
            ->selectRaw('NULL as source_type')
            ->selectRaw('NULL as purchase_order_metadata')
            ->selectRaw('NULL as ordered_at')
            ->selectRaw('NULL as expected_at')
            ->selectRaw('0 as qty_ordered')
            ->selectRaw('0 as qty_received')
            ->selectRaw('0 as qty_cancelled')
            ->selectRaw('0 as qty_outstanding')
            ->selectRaw('0 as shipments_count')
            ->selectRaw('0 as attempt_count')
            ->selectRaw('NULL as next_retry_at')
            ->selectRaw('NULL as updated_at')
            ->selectRaw('0 as is_receivable')
            ->selectRaw('NULL as profile_name')
            ->selectRaw('NULL as tracking_numbers');
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        match ($filters['scope']) {
            'attention' => $query->where(function (Builder $attention): void {
                $attention
                    ->where(function (Builder $imports): void {
                        $imports
                            ->where('row_type', 'import')
                            ->whereIn('status', [
                                PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                                PurchaseOrderImport::STATUS_FAILED,
                                PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
                            ]);
                    })
                    ->orWhere(function (Builder $overdue): void {
                        $overdue
                            ->where('row_type', 'purchase_order')
                            ->where('is_receivable', 1)
                            ->whereNotNull('expected_at')
                            ->whereDate('expected_at', '<', today()->toDateString());
                    });
            }),
            'incoming' => $query->where('row_type', 'import'),
            'orders' => $query->where('row_type', 'purchase_order'),
            'receiving' => $query->where('is_receivable', 1),
            'completed' => $query->where(function (Builder $completed): void {
                $completed
                    ->where(function (Builder $orders): void {
                        $orders
                            ->where('row_type', 'purchase_order')
                            ->whereIn('status', [
                                PurchaseOrder::STATUS_RECEIVED,
                                PurchaseOrder::STATUS_CLOSED,
                            ]);
                    })
                    ->orWhere(function (Builder $imports): void {
                        $imports
                            ->where('row_type', 'import')
                            ->whereIn('status', [
                                PurchaseOrderImport::STATUS_IMPORTED,
                                PurchaseOrderImport::STATUS_DUPLICATE,
                                PurchaseOrderImport::STATUS_REJECTED,
                                PurchaseOrderImport::STATUS_CANCELLED,
                            ]);
                    });
            }),
            default => null,
        };

        $query
            ->when(
                $filters['status'],
                fn (Builder $builder, string $status) => $builder->where('status', $status),
            )
            ->when(
                $filters['stage'],
                fn (Builder $builder, string $stage) => $builder->where('stage', $stage),
            )
            ->when(
                $filters['vendor_id'],
                fn (Builder $builder, int $vendorId) => $builder->where('vendor_id', $vendorId),
            )
            ->when(
                $filters['warehouse_id'],
                fn (Builder $builder, int $warehouseId) => $builder
                    ->where('destination_warehouse_id', $warehouseId),
            )
            ->when(
                $filters['expected_after'],
                fn (Builder $builder, string $date) => $builder->whereDate('expected_at', '>=', $date),
            )
            ->when(
                $filters['expected_before'],
                fn (Builder $builder, string $date) => $builder->whereDate('expected_at', '<=', $date),
            )
            ->when(
                $filters['method'],
                fn (Builder $builder, string $method) => $builder->where('import_method', $method),
            )
            ->when($filters['tracking_number'], function (Builder $builder, string $tracking): void {
                $builder->where(
                    'tracking_numbers',
                    'like',
                    '%'.$this->escapeLike($tracking).'%',
                );
            })
            ->when($filters['q'], function (Builder $builder, string $search): void {
                $term = '%'.$this->escapeLike($search).'%';
                $builder->where(function (Builder $nested) use ($search, $term): void {
                    $nested
                        ->where('order_number', 'like', $term)
                        ->orWhere('supplier_order_number', 'like', $term)
                        ->orWhere('supplier_name', 'like', $term)
                        ->orWhere('reason_code', 'like', $term)
                        ->orWhere('tracking_numbers', 'like', $term);

                    if (ctype_digit($search)) {
                        $nested->orWhere('row_id', (int) $search);
                    }
                });
            });
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $sort = $filters['sort'];
        $direction = $filters['direction'];

        if ($sort === null) {
            $query
                ->orderByDesc('updated_at')
                ->orderBy('row_type')
                ->orderByDesc('row_id');

            return;
        }

        match ($sort) {
            'order' => $query
                ->orderByRaw(
                    "CASE WHEN COALESCE(NULLIF(order_number, ''), supplier_order_number) "
                    .'IS NULL THEN 1 ELSE 0 END',
                )
                ->orderByRaw(
                    "COALESCE(NULLIF(order_number, ''), supplier_order_number) ".$direction,
                ),
            'supplier' => $query
                ->orderByRaw('CASE WHEN supplier_name IS NULL THEN 1 ELSE 0 END')
                ->orderBy('supplier_name', $direction),
            'status' => $query->orderBy('status', $direction),
            'expected' => $query
                ->orderByRaw('CASE WHEN expected_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expected_at', $direction),
            'progress' => $query->orderByRaw(
                'CASE WHEN qty_ordered <= 0 THEN 0 '
                .'WHEN qty_received >= qty_ordered THEN 1 '
                .'ELSE (qty_received * 1.0) / qty_ordered END '.$direction,
            ),
            'outstanding' => $query->orderBy('qty_outstanding', $direction),
            'updated' => $query->orderBy('updated_at', $direction),
            'destination' => $query
                ->orderByRaw('CASE WHEN destination_name IS NULL THEN 1 ELSE 0 END')
                ->orderBy('destination_name', $direction),
            'shipments' => $query->orderBy('shipments_count', $direction),
            'received' => $query->orderBy('qty_received', $direction),
        };

        $query
            ->orderBy('row_type')
            ->orderByRaw(
                "COALESCE(NULLIF(order_number, ''), supplier_order_number, '')",
            )
            ->orderBy('row_id');
    }

    private function normalizeDate(mixed $value): ?string
    {
        $date = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year) ? $date : null;
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
