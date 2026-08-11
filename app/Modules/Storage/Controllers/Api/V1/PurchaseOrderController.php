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
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Storage Purchase Orders',
    description: 'Purchase-order, shipment, tracking, goods-receipt, and reversal operations.'
)]
class PurchaseOrderController extends Controller
{
    #[OA\Get(
        path: '/api/v1/storage/purchase-orders',
        operationId: 'getStoragePurchaseOrderList',
        summary: 'List Storage purchase orders',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'vendor_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'tracking_number', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'expected_after', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'expected_before', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated purchase-order list'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.read ability'),
            new OA\Response(response: 422, description: 'Invalid filter'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}',
        operationId: 'getStoragePurchaseOrder',
        summary: 'Get a purchase order with shipment and receipt history',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Purchase order'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.read ability'),
            new OA\Response(response: 404, description: 'Purchase order not found'),
        ]
    )]
    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return new PurchaseOrderResource($this->loadOrder($purchaseOrder));
    }

    #[OA\Post(
        path: '/api/v1/storage/purchase-orders',
        operationId: 'createStoragePurchaseOrder',
        summary: 'Register an externally placed purchase order or draft need',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['po_number', 'vendor_id', 'deliver_to_warehouse_id', 'status', 'currency', 'lines'],
                properties: [
                    new OA\Property(property: 'po_number', type: 'string'),
                    new OA\Property(property: 'vendor_id', type: 'integer'),
                    new OA\Property(property: 'deliver_to_warehouse_id', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'ordered']),
                    new OA\Property(property: 'ordered_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'expected_at', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'currency', type: 'string', example: 'NOK'),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Purchase order created'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.manage ability'),
            new OA\Response(response: 422, description: 'Validation or lifecycle error'),
        ]
    )]
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

    #[OA\Put(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}',
        operationId: 'updateStoragePurchaseOrder',
        summary: 'Update editable purchase-order data and lines',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Purchase order updated'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.manage ability'),
            new OA\Response(response: 404, description: 'Purchase order not found'),
            new OA\Response(response: 422, description: 'Validation or lifecycle error'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}/shipments',
        operationId: 'createStoragePurchaseShipment',
        summary: 'Register a shipment with allocations and tracking identifiers',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Shipment created'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.manage ability'),
            new OA\Response(response: 422, description: 'Validation or lifecycle error'),
        ]
    )]
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

    #[OA\Patch(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/status',
        operationId: 'updateStoragePurchaseShipmentStatus',
        summary: 'Change a shipment manual lifecycle status',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'purchaseShipment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'reason'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['in_transit', 'delivered', 'cancelled']),
                    new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'reason', type: 'string'),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Shipment status updated'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.manage ability'),
            new OA\Response(response: 404, description: 'Order or shipment not found'),
            new OA\Response(response: 422, description: 'Validation or lifecycle error'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}/shipments/{purchaseShipment}/trackings',
        operationId: 'appendStoragePurchaseShipmentTracking',
        summary: 'Append a tracking identifier to an existing shipment',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'purchaseShipment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['tracking_number'],
                properties: [
                    new OA\Property(property: 'shipping_carrier_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'tracking_number', type: 'string'),
                    new OA\Property(property: 'tracking_type', type: 'string', enum: ['master', 'parcel', 'last_mile', 'other', 'legacy']),
                    new OA\Property(property: 'label', type: 'string', nullable: true),
                    new OA\Property(property: 'direct_url', type: 'string', format: 'uri', nullable: true),
                    new OA\Property(property: 'sort_order', type: 'integer', minimum: 0, maximum: 65535, nullable: true),
                    new OA\Property(property: 'metadata', type: 'object', nullable: true),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Tracking identifier appended'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.manage ability'),
            new OA\Response(response: 422, description: 'Validation or safe-link error'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}/lines/{purchaseOrderLine}/cancel',
        operationId: 'cancelStoragePurchaseOrderLineQuantity',
        summary: 'Cancel an outstanding purchase-order line quantity',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'purchaseOrderLine', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['quantity', 'reason'], type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Quantity cancelled and order lifecycle refreshed'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.manage ability'),
            new OA\Response(response: 422, description: 'Validation or lifecycle error'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}/close',
        operationId: 'closeStoragePurchaseOrder',
        summary: 'Close a fully received purchase order',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Purchase order closed'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.manage ability'),
            new OA\Response(response: 422, description: 'Validation or lifecycle error'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}/cancel',
        operationId: 'cancelStoragePurchaseOrder',
        summary: 'Cancel an eligible unreceived purchase order',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Purchase order cancelled'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.manage ability'),
            new OA\Response(response: 422, description: 'Validation or lifecycle error'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}/receipts',
        operationId: 'postStoragePurchaseReceipt',
        description: 'Posts accepted quantities atomically. Reusing the same token with the same payload returns the existing receipt; a different payload is rejected. Overages additionally require storage.purchase.receive_overage.',
        summary: 'Post an idempotent goods receipt',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idempotency_token', 'lines'],
                properties: [
                    new OA\Property(property: 'idempotency_token', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'purchase_shipment_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'delivery_note_ref', type: 'string', nullable: true),
                    new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'warehouse_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'room_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'box_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Existing idempotent receipt'),
            new OA\Response(response: 201, description: 'Receipt posted and inventory updated'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.receive ability'),
            new OA\Response(response: 422, description: 'Validation, idempotency, overage, or lifecycle error'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/storage/purchase-orders/{purchaseOrder}/receipts/{purchaseReceipt}/reverse',
        operationId: 'reverseStoragePurchaseReceipt',
        description: 'Creates an immutable negative receipt and stock movements. The operation is guarded and idempotent.',
        summary: 'Reverse a posted goods receipt',
        security: [['bearerAuth' => []]],
        tags: ['Storage Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'purchaseOrder', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'purchaseReceipt', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['idempotency_token', 'reason'],
                properties: [
                    new OA\Property(property: 'idempotency_token', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'reason', type: 'string'),
                    new OA\Property(property: 'reversed_at', type: 'string', format: 'date-time', nullable: true),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Existing idempotent reversal'),
            new OA\Response(response: 201, description: 'Reversal receipt posted'),
            new OA\Response(response: 403, description: 'Missing storage.purchase.reverse ability'),
            new OA\Response(response: 422, description: 'Validation, idempotency, stock-safety, or lifecycle error'),
        ]
    )]
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
            return $request->user()->tokenCan('storage.purchase.receive_overage');
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
