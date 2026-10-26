<?php

namespace App\Modules\Storage\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\AppendPurchaseShipmentTracking;
use App\Modules\Storage\Actions\CancelPurchaseOrder;
use App\Modules\Storage\Actions\CancelPurchaseOrderLine;
use App\Modules\Storage\Actions\ClosePurchaseOrder;
use App\Modules\Storage\Actions\PostPurchaseReceipt;
use App\Modules\Storage\Actions\ReversePurchaseReceipt;
use App\Modules\Storage\Actions\StorePurchaseOrder;
use App\Modules\Storage\Actions\StorePurchaseShipment;
use App\Modules\Storage\Actions\UpdatePurchaseOrder;
use App\Modules\Storage\Actions\UpdatePurchaseShipmentStatus;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Queries\PurchaseOrderIndexQuery;
use App\Modules\Storage\Requests\Tech\CancelPurchaseOrderLineRequest;
use App\Modules\Storage\Requests\Tech\PostPurchaseReceiptRequest;
use App\Modules\Storage\Requests\Tech\PurchaseOrderReasonRequest;
use App\Modules\Storage\Requests\Tech\ReversePurchaseReceiptRequest;
use App\Modules\Storage\Requests\Tech\SavePurchaseOrderRequest;
use App\Modules\Storage\Requests\Tech\StorePurchaseShipmentRequest;
use App\Modules\Storage\Resources\Api\V1\PurchaseOrderResource;
use App\Modules\Storage\Resources\Api\V1\PurchaseReceiptResource;
use App\Modules\Storage\Resources\Api\V1\PurchaseShipmentResource;
use App\Modules\Storage\Resources\Api\V1\PurchaseShipmentTrackingResource;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

class PurchaseOrderController extends Controller
{
    public function index(Request $request, PurchaseOrderIndexQuery $query)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(PurchaseOrder::statuses())],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:storage_warehouses,id'],
            'expected_after' => ['nullable', 'date'],
            'expected_before' => ['nullable', 'date', 'after_or_equal:expected_after'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 25);
        unset($filters['per_page']);

        return PurchaseOrderResource::collection($query->paginate($filters, $perPage));
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return new PurchaseOrderResource(app(\App\Modules\Storage\Queries\PurchaseOrderApiDetailQuery::class)
            ->sort($this->loadOrder($purchaseOrder), $request));
    }

    public function store(
        SavePurchaseOrderRequest $request,
        StorePurchaseOrder $storePurchaseOrder
    ) {
        $purchaseOrder = $this->execute(
            fn () => $storePurchaseOrder->handle($request->validated(), $request->user()),
            'purchase_order'
        );

        return (new PurchaseOrderResource($this->loadOrder($purchaseOrder)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        SavePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        UpdatePurchaseOrder $updatePurchaseOrder
    ): PurchaseOrderResource {
        $purchaseOrder = $this->execute(
            fn () => $updatePurchaseOrder->handle($purchaseOrder, $request->validated(), $request->user()),
            'purchase_order'
        );

        return new PurchaseOrderResource($this->loadOrder($purchaseOrder));
    }

    public function storeShipment(
        StorePurchaseShipmentRequest $request,
        PurchaseOrder $purchaseOrder,
        StorePurchaseShipment $storePurchaseShipment
    ) {
        $shipment = $this->execute(
            fn () => $storePurchaseShipment->handle($purchaseOrder, $request->validated(), $request->user()),
            'shipment'
        );

        return (new PurchaseShipmentResource($this->loadShipment($shipment)))
            ->response()
            ->setStatusCode(201);
    }

    public function updateShipmentStatus(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseShipment $purchaseShipment,
        UpdatePurchaseShipmentStatus $updatePurchaseShipmentStatus
    ): PurchaseShipmentResource {
        $this->assertShipmentBelongsToOrder($purchaseShipment, $purchaseOrder);
        $data = $request->validate([
            'status' => ['required', Rule::in([
                PurchaseShipment::STATUS_IN_TRANSIT,
                PurchaseShipment::STATUS_DELIVERED,
                PurchaseShipment::STATUS_CANCELLED,
            ])],
            'occurred_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $shipment = $this->execute(
            fn () => $updatePurchaseShipmentStatus->handle(
                $purchaseShipment,
                $data['status'],
                isset($data['occurred_at']) ? $request->date('occurred_at') : null,
                $data['reason'],
                $request->user()
            ),
            'shipment'
        );

        return new PurchaseShipmentResource($this->loadShipment($shipment));
    }

    public function appendTracking(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseShipment $purchaseShipment,
        AppendPurchaseShipmentTracking $appendPurchaseShipmentTracking
    ) {
        $this->assertShipmentBelongsToOrder($purchaseShipment, $purchaseOrder);
        $data = $request->validate([
            'shipping_carrier_id' => ['nullable', 'integer', 'exists:shipping_carriers,id'],
            'tracking_number' => ['required', 'string', 'max:255'],
            'tracking_type' => ['nullable', Rule::in(['master', 'parcel', 'last_mile', 'other', 'legacy'])],
            'label' => ['nullable', 'string', 'max:255'],
            'direct_url' => ['nullable', 'url', 'max:4096'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'metadata' => ['nullable', 'array'],
        ]);
        $tracking = $this->execute(
            fn () => $appendPurchaseShipmentTracking->handle($purchaseShipment, $data, $request->user()),
            'tracking'
        );

        return (new PurchaseShipmentTrackingResource($tracking))
            ->response()
            ->setStatusCode(201);
    }

    public function cancelLine(
        CancelPurchaseOrderLineRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderLine $purchaseOrderLine,
        CancelPurchaseOrderLine $cancelPurchaseOrderLine
    ): PurchaseOrderResource {
        $this->assertLineBelongsToOrder($purchaseOrderLine, $purchaseOrder);
        $data = $request->validated();
        $this->execute(
            fn () => $cancelPurchaseOrderLine->handle(
                $purchaseOrder,
                $purchaseOrderLine,
                (int) $data['quantity'],
                $data['reason'],
                $request->user()
            ),
            'purchase_order_line'
        );

        return new PurchaseOrderResource($this->loadOrder($purchaseOrder->refresh()));
    }

    public function close(
        PurchaseOrderReasonRequest $request,
        PurchaseOrder $purchaseOrder,
        ClosePurchaseOrder $closePurchaseOrder
    ): PurchaseOrderResource {
        $purchaseOrder = $this->execute(
            fn () => $closePurchaseOrder->handle(
                $purchaseOrder,
                $request->validated('reason'),
                $request->user()
            ),
            'purchase_order'
        );

        return new PurchaseOrderResource($this->loadOrder($purchaseOrder));
    }

    public function cancel(
        PurchaseOrderReasonRequest $request,
        PurchaseOrder $purchaseOrder,
        CancelPurchaseOrder $cancelPurchaseOrder
    ): PurchaseOrderResource {
        $purchaseOrder = $this->execute(
            fn () => $cancelPurchaseOrder->handle(
                $purchaseOrder,
                $request->validated('reason'),
                $request->user()
            ),
            'purchase_order'
        );

        return new PurchaseOrderResource($this->loadOrder($purchaseOrder));
    }

    public function postReceipt(
        PostPurchaseReceiptRequest $request,
        PurchaseOrder $purchaseOrder,
        PostPurchaseReceipt $postPurchaseReceipt
    ) {
        $data = $request->validated();
        $data['lines'] = collect($data['lines'])
            ->filter(fn (array $line): bool => (int) $line['qty_accepted'] > 0 || (int) $line['qty_rejected'] > 0)
            ->values()
            ->all();

        $receipt = $this->execute(
            fn () => $postPurchaseReceipt->handle(
                $purchaseOrder,
                $data,
                $request->user(),
                $this->canReceiveOverage($request)
            ),
            'receipt'
        );
        $status = $receipt->wasRecentlyCreated ? 201 : 200;

        return (new PurchaseReceiptResource($this->loadReceipt($receipt)))
            ->response()
            ->setStatusCode($status);
    }

    public function reverseReceipt(
        ReversePurchaseReceiptRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseReceipt $purchaseReceipt,
        ReversePurchaseReceipt $reversePurchaseReceipt
    ) {
        $this->assertReceiptBelongsToOrder($purchaseReceipt, $purchaseOrder);
        $reversal = $this->execute(
            fn () => $reversePurchaseReceipt->handle(
                $purchaseReceipt,
                $request->validated(),
                $request->user()
            ),
            'receipt'
        );
        $status = $reversal->wasRecentlyCreated ? 201 : 200;

        return (new PurchaseReceiptResource($this->loadReceipt($reversal)))
            ->response()
            ->setStatusCode($status);
    }

    private function loadOrder(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $purchaseOrder->load([
            'vendor',
            'deliverToWarehouse',
            'lines.item',
            'lines.ticket',
            'shipments.purchaseOrder:id,status',
            'shipments.carrier',
            'shipments.lines.purchaseOrderLine.item',
            'shipments.trackings.carrier',
            'receipts.creator',
            'receipts.shipment',
            'receipts.lines.purchaseOrderLine.item',
            'receipts.lines.units.stockUnit',
            'receipts.reversal',
            'receipts.reversalOf',
        ])->loadCount(['lines', 'shipments']);
    }

    private function loadShipment(PurchaseShipment $shipment): PurchaseShipment
    {
        return $shipment->load([
            'purchaseOrder:id,status',
            'carrier',
            'lines.purchaseOrderLine.item',
            'trackings.carrier',
        ])->loadCount('receipts');
    }

    private function loadReceipt(PurchaseReceipt $receipt): PurchaseReceipt
    {
        return $receipt->load([
            'lines.purchaseOrderLine.item',
            'lines.units.stockUnit',
            'reversal',
            'reversalOf',
        ]);
    }

    private function assertLineBelongsToOrder(PurchaseOrderLine $line, PurchaseOrder $purchaseOrder): void
    {
        abort_unless((int) $line->purchase_order_id === (int) $purchaseOrder->id, 404);
    }

    private function assertShipmentBelongsToOrder(PurchaseShipment $shipment, PurchaseOrder $purchaseOrder): void
    {
        abort_unless((int) $shipment->purchase_order_id === (int) $purchaseOrder->id, 404);
    }

    private function assertReceiptBelongsToOrder(PurchaseReceipt $receipt, PurchaseOrder $purchaseOrder): void
    {
        abort_unless((int) $receipt->purchase_order_id === (int) $purchaseOrder->id, 404);
    }

    private function canReceiveOverage(Request $request): bool
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return $request->user()->tokenCan('storage.purchase.receive_overage')
                && \App\Modules\Storage\Support\StorageApiAccess::allows($request->user(), 'storage.purchase_receive_overage');
        }

        // Sanctum transient tokens report every token ability as allowed. Browser-session callers
        // therefore fall back to the equivalent web permission instead of gaining overage access.
        return $request->user()->can('storage.purchase_receive_overage');
    }

    private function execute(callable $callback, string $field)
    {
        try {
            return $callback();
        } catch (InvalidArgumentException|DomainException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
