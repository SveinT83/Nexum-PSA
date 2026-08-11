<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\CancelPurchaseOrder;
use App\Modules\Storage\Actions\CancelPurchaseOrderLine;
use App\Modules\Storage\Actions\ClosePurchaseOrder;
use App\Modules\Storage\Actions\StorePurchaseOrder;
use App\Modules\Storage\Actions\UpdatePurchaseOrder;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Queries\SupplierOrderWorkspaceQuery;
use App\Modules\Storage\Requests\Tech\CancelPurchaseOrderLineRequest;
use App\Modules\Storage\Requests\Tech\PurchaseOrderReasonRequest;
use App\Modules\Storage\Requests\Tech\SavePurchaseOrderRequest;
use App\Modules\Storage\Support\CollectionTableSorter;
use App\Modules\Storage\Support\SupplierOrderSourceSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request, SupplierOrderWorkspaceQuery $query): View
    {
        return view(
            'storage::Tech.Storage.purchase-orders.index',
            $query->viewData(
                $request,
                includePurchaseOrders: true,
                includeImports: $request->user()->can('storage.purchase_import_view'),
            ),
        );
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $timezone = $user?->preferences()->value('timezone')
            ?? $user?->profile()->value('timezone')
            ?? config('app.timezone', 'UTC');
        $purchaseOrder = new PurchaseOrder([
            'status' => PurchaseOrder::STATUS_ORDERED,
            'currency' => 'NOK',
            'ordered_at' => now($timezone)->toDateString(),
        ]);

        return view('storage::Tech.Storage.purchase-orders.form', $this->formData($purchaseOrder));
    }

    public function store(
        SavePurchaseOrderRequest $request,
        StorePurchaseOrder $storePurchaseOrder
    ): RedirectResponse {
        try {
            $purchaseOrder = $storePurchaseOrder->handle($request->validated(), $request->user());
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['purchase_order' => $exception->getMessage()]);
        }

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order registered.');
    }

    public function show(
        Request $request,
        PurchaseOrder $purchaseOrder,
        CollectionTableSorter $sorter,
        SupplierOrderSourceSnapshot $sourceSnapshotSanitizer
    ): View {
        $purchaseOrder->load([
            'vendor',
            'deliverToWarehouse',
            'statusChanger',
            'lines.item',
            'lines.ticket',
            'lines.shipmentLines',
            'shipments.carrier',
            'shipments.lines.purchaseOrderLine',
            'shipments.trackings.carrier',
            'shipments.receipts',
            'receipts.creator',
            'receipts.shipment',
            'receipts.lines.units',
            'receipts.reversal',
            'receipts.reversalOf',
        ]);

        $sourceImport = null;
        $sourceSnapshot = [];
        $canOpenSourceInbox = false;
        if ($request->user()->can('storage.purchase_import_view')) {
            $purchaseOrder->load('supplierOrderImport.emailMessage');
            $sourceImport = $purchaseOrder->supplierOrderImport;
            $hasEmailSource = $sourceImport !== null && (
                $sourceImport->source_domain === 'email'
                || $sourceImport->source_type === 'email_message'
                || data_get($sourceImport->safe_source_snapshot, 'source') === 'email'
            );

            if ($hasEmailSource) {
                $sourceSnapshot = $sourceSnapshotSanitizer->sanitizeStoredSnapshot(
                    (array) ($sourceImport->safe_source_snapshot ?? []),
                    (array) ($sourceImport->trusted_auth_snapshot ?? []),
                );
            } else {
                $sourceImport = null;
            }

            $canOpenSourceInbox = $sourceImport !== null
                && $sourceImport->email_message_id !== null
                && $request->user()->can('email.inbox_view');

            $canOpenSourceInbox = $canOpenSourceInbox && $sourceImport->emailMessage !== null;
        }

        $stats = [
            'ordered' => $purchaseOrder->lines->sum('qty_ordered'),
            'received' => $purchaseOrder->lines->sum('qty_received'),
            'cancelled' => $purchaseOrder->lines->sum('qty_cancelled'),
            'outstanding' => $purchaseOrder->lines->sum('qty_outstanding'),
        ];

        $orderLineColumns = [
            'item' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (PurchaseOrderLine $line): ?string => $line->sku_snapshot ?: $line->item?->sku,
            ],
            'supplier_sku' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (PurchaseOrderLine $line): ?string => $line->supplier_sku_snapshot,
            ],
            'ordered' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (PurchaseOrderLine $line): int => $line->qty_ordered,
            ],
            'received' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (PurchaseOrderLine $line): int => $line->qty_received,
            ],
            'cancelled' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (PurchaseOrderLine $line): int => $line->qty_cancelled,
            ],
            'outstanding' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (PurchaseOrderLine $line): int => $line->qty_outstanding,
            ],
            'expected' => [
                'type' => CollectionTableSorter::TYPE_DATE,
                'value' => fn (PurchaseOrderLine $line) => $line->expected_at,
            ],
            'source' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => function (PurchaseOrderLine $line) use ($request): string {
                    if (! $line->ticket) {
                        return 'Manual';
                    }

                    return $request->user()->can('ticket.view') ? $line->ticket->ticket_key : 'Linked';
                },
            ],
        ];
        $shipmentLineColumns = [
            'item' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (mixed $line): ?string => $line->purchaseOrderLine?->sku_snapshot,
            ],
            'allocated' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (mixed $line): int => (int) $line->qty_allocated,
            ],
            'accepted' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (mixed $line): int => (int) $line->qty_received,
            ],
            'rejected' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (mixed $line): int => (int) $line->qty_rejected,
            ],
            'cancelled' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (mixed $line): int => (int) $line->qty_cancelled,
            ],
            'outstanding' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (mixed $line): int => (int) $line->qty_outstanding,
            ],
        ];
        $receiptColumns = [
            'receipt' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (PurchaseReceipt $receipt): string => $receipt->receipt_number,
            ],
            'received' => [
                'type' => CollectionTableSorter::TYPE_DATE,
                'value' => fn (PurchaseReceipt $receipt) => $receipt->received_at,
            ],
            'shipment' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (PurchaseReceipt $receipt): ?string => $receipt->shipment?->reference,
            ],
            'accepted' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (PurchaseReceipt $receipt): int => ($receipt->receipt_type === PurchaseReceipt::TYPE_REVERSAL ? -1 : 1)
                    * $receipt->lines->sum('qty_accepted'),
            ],
            'rejected' => [
                'type' => CollectionTableSorter::TYPE_NUMBER,
                'value' => fn (PurchaseReceipt $receipt): int => ($receipt->receipt_type === PurchaseReceipt::TYPE_REVERSAL ? -1 : 1)
                    * $receipt->lines->sum('qty_rejected'),
            ],
            'status' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (PurchaseReceipt $receipt): string => $receipt->status,
            ],
            'actor' => [
                'type' => CollectionTableSorter::TYPE_STRING,
                'value' => fn (PurchaseReceipt $receipt): string => $receipt->creator?->name ?: 'Unknown',
            ],
        ];

        $orderLineSort = $sorter->normalizeColumn($request->query('order_line_sort'), $orderLineColumns);
        $orderLineDirection = $sorter->normalizeDirection($request->query('order_line_direction'));
        $shipmentLineSort = $sorter->normalizeColumn(
            $request->query('shipment_line_sort'),
            $shipmentLineColumns
        );
        $shipmentLineDirection = $sorter->normalizeDirection($request->query('shipment_line_direction'));
        $receiptSort = $sorter->normalizeColumn($request->query('receipt_sort'), $receiptColumns);
        $receiptDirection = $sorter->normalizeDirection(
            $request->query('receipt_direction'),
            $receiptSort === 'received' ? 'desc' : 'asc'
        );

        $orderLines = $sorter->sort(
            $purchaseOrder->lines,
            $orderLineSort,
            $orderLineDirection,
            $orderLineColumns
        );
        $shipments = $purchaseOrder->shipments->map(function (PurchaseShipment $shipment) use (
            $sorter,
            $shipmentLineSort,
            $shipmentLineDirection,
            $shipmentLineColumns
        ): PurchaseShipment {
            $shipment->setRelation('lines', $sorter->sort(
                $shipment->lines,
                $shipmentLineSort,
                $shipmentLineDirection,
                $shipmentLineColumns
            ));

            return $shipment;
        });
        $receipts = $purchaseOrder->receipts->sortByDesc('received_at')->values();
        $receipts = $sorter->sort($receipts, $receiptSort, $receiptDirection, $receiptColumns);
        $detailSortQuery = array_filter([
            'order_line_sort' => $orderLineSort,
            'order_line_direction' => $orderLineSort === null ? null : $orderLineDirection,
            'shipment_line_sort' => $shipmentLineSort,
            'shipment_line_direction' => $shipmentLineSort === null ? null : $shipmentLineDirection,
            'receipt_sort' => $receiptSort,
            'receipt_direction' => $receiptSort === null ? null : $receiptDirection,
        ], fn (mixed $value): bool => $value !== null);

        $hasReceiptHistory = $purchaseOrder->receipts->contains(
            fn (PurchaseReceipt $receipt): bool => in_array($receipt->status, [
                PurchaseReceipt::STATUS_POSTED,
                PurchaseReceipt::STATUS_REVERSED,
            ], true)
        );
        $hasActiveShipment = $purchaseOrder->shipments->contains(
            fn (PurchaseShipment $shipment): bool => $shipment->status !== PurchaseShipment::STATUS_CANCELLED
        );

        return view('storage::Tech.Storage.purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder,
            'stats' => $stats,
            'orderLines' => $orderLines,
            'shipments' => $shipments,
            'receipts' => $receipts,
            'orderLineSort' => $orderLineSort,
            'orderLineDirection' => $orderLineDirection,
            'shipmentLineSort' => $shipmentLineSort,
            'shipmentLineDirection' => $shipmentLineDirection,
            'receiptSort' => $receiptSort,
            'receiptDirection' => $receiptDirection,
            'detailSortQuery' => $detailSortQuery,
            'canEditLifecycle' => in_array($purchaseOrder->status, [
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_ORDERED,
            ], true),
            'canAddShipmentLifecycle' => in_array($purchaseOrder->status, [
                PurchaseOrder::STATUS_ORDERED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ], true),
            'canReceiveLifecycle' => in_array($purchaseOrder->status, [
                PurchaseOrder::STATUS_ORDERED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ], true) && $stats['outstanding'] > 0,
            'canMutateShipmentLifecycle' => ! in_array($purchaseOrder->status, [
                PurchaseOrder::STATUS_CLOSED,
                PurchaseOrder::STATUS_CANCELLED,
            ], true),
            'canCancelLinesLifecycle' => in_array($purchaseOrder->status, [
                PurchaseOrder::STATUS_ORDERED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ], true) && $stats['outstanding'] > 0,
            'canCloseOrderLifecycle' => $purchaseOrder->status === PurchaseOrder::STATUS_RECEIVED
                && $stats['outstanding'] === 0,
            'canCancelOrderLifecycle' => in_array($purchaseOrder->status, [
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_ORDERED,
            ], true) && ! $hasReceiptHistory && ! $hasActiveShipment,
            'sourceImport' => $sourceImport,
            'sourceSnapshot' => $sourceSnapshot,
            'canOpenSourceInbox' => $canOpenSourceInbox,
            'carriers' => ShippingCarrier::query()
                ->where('lifecycle_state', '<>', ShippingCarrier::LIFECYCLE_INACTIVE)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): View
    {
        abort_unless(in_array($purchaseOrder->status, [
            PurchaseOrder::STATUS_DRAFT,
            PurchaseOrder::STATUS_ORDERED,
        ], true), 409, 'This purchase order is locked for normal editing.');

        $purchaseOrder->load(['lines.item', 'lines.shipmentLines', 'lines.receiptLines']);

        return view('storage::Tech.Storage.purchase-orders.form', $this->formData($purchaseOrder));
    }

    public function update(
        SavePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        UpdatePurchaseOrder $updatePurchaseOrder
    ): RedirectResponse {
        try {
            $purchaseOrder = $updatePurchaseOrder->handle(
                $purchaseOrder,
                $request->validated(),
                $request->user()
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['purchase_order' => $exception->getMessage()]);
        }

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order updated.');
    }

    public function cancelLine(
        CancelPurchaseOrderLineRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderLine $purchaseOrderLine,
        CancelPurchaseOrderLine $cancelPurchaseOrderLine
    ): RedirectResponse {
        $cancelPurchaseOrderLine->handle(
            $purchaseOrder,
            $purchaseOrderLine,
            $request->integer('quantity'),
            $request->string('reason')->toString(),
            $request->user()
        );

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Outstanding line quantity cancelled.');
    }

    public function close(
        PurchaseOrderReasonRequest $request,
        PurchaseOrder $purchaseOrder,
        ClosePurchaseOrder $closePurchaseOrder
    ): RedirectResponse {
        $closePurchaseOrder->handle(
            $purchaseOrder,
            $request->string('reason')->toString(),
            $request->user()
        );

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order closed and locked.');
    }

    public function cancel(
        PurchaseOrderReasonRequest $request,
        PurchaseOrder $purchaseOrder,
        CancelPurchaseOrder $cancelPurchaseOrder
    ): RedirectResponse {
        $cancelPurchaseOrder->handle(
            $purchaseOrder,
            $request->string('reason')->toString(),
            $request->user()
        );

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order cancelled.');
    }

    private function formData(PurchaseOrder $purchaseOrder): array
    {
        $currentItemIds = $purchaseOrder->exists
            ? $purchaseOrder->lines()->pluck('item_id')
            : collect();

        $items = Item::query()
            ->with(['primaryVendor', 'itemVendors'])
            ->where(function ($query) use ($currentItemIds): void {
                $query->where(function ($active): void {
                    $active->where('status', 'active')->where('can_be_ordered', true);
                });

                if ($currentItemIds->isNotEmpty()) {
                    $query->orWhereIn('id', $currentItemIds);
                }
            })
            ->orderBy('name')
            ->get();

        $suppliers = Vendor::query()
            ->where('is_supplier', true)
            ->where(function ($query) use ($purchaseOrder): void {
                $query->where('is_active', true);

                if ($purchaseOrder->vendor_id) {
                    $query->orWhere('id', $purchaseOrder->vendor_id);
                }
            })
            ->orderBy('name')
            ->get();

        $warehouses = Warehouse::query()
            ->where(function ($query) use ($purchaseOrder): void {
                $query->where('is_active', true);

                if ($purchaseOrder->deliver_to_warehouse_id) {
                    $query->orWhere('id', $purchaseOrder->deliver_to_warehouse_id);
                }
            })
            ->orderBy('name')
            ->get();

        $hasOperationalHistory = $purchaseOrder->exists && (
            $purchaseOrder->shipments()->exists()
            || $purchaseOrder->receipts()->exists()
        );

        return [
            'purchaseOrder' => $purchaseOrder,
            'hasOperationalHistory' => $hasOperationalHistory,
            'items' => $items,
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
        ];
    }
}
