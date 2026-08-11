<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Storage\Actions\AppendPurchaseShipmentTracking;
use App\Modules\Storage\Actions\StorePurchaseShipment;
use App\Modules\Storage\Actions\UpdatePurchaseShipmentStatus;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Requests\Tech\AppendPurchaseShipmentTrackingRequest;
use App\Modules\Storage\Requests\Tech\StorePurchaseShipmentRequest;
use App\Modules\Storage\Requests\Tech\UpdatePurchaseShipmentStatusRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PurchaseShipmentController extends Controller
{
    public function create(PurchaseOrder $purchaseOrder): View
    {
        abort_unless(in_array($purchaseOrder->status, [
            PurchaseOrder::STATUS_ORDERED,
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        ], true), 409, 'Only placed orders with outstanding goods can accept shipments.');

        $purchaseOrder->load([
            'vendor',
            'deliverToWarehouse',
            'lines.item',
            'lines.shipmentLines.shipment',
            'shipments',
        ]);

        return view('storage::Tech.Storage.purchase-orders.shipments.create', [
            'purchaseOrder' => $purchaseOrder,
            'carriers' => ShippingCarrier::query()
                ->where('lifecycle_state', '<>', ShippingCarrier::LIFECYCLE_INACTIVE)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(
        StorePurchaseShipmentRequest $request,
        PurchaseOrder $purchaseOrder,
        StorePurchaseShipment $storePurchaseShipment
    ): RedirectResponse {
        try {
            $storePurchaseShipment->handle(
                $purchaseOrder,
                $request->validated(),
                $request->user()
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['shipment' => $exception->getMessage()]);
        }

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Shipment registered.');
    }

    public function updateStatus(
        UpdatePurchaseShipmentStatusRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseShipment $purchaseShipment,
        UpdatePurchaseShipmentStatus $updatePurchaseShipmentStatus
    ): RedirectResponse {
        $this->assertShipmentBelongsToOrder($purchaseOrder, $purchaseShipment);
        $data = $request->validated();
        $occurredAt = filled($data['occurred_at'] ?? null)
            ? CarbonImmutable::parse($data['occurred_at'])
            : null;

        $updatePurchaseShipmentStatus->handle(
            $purchaseShipment,
            $data['status'],
            $occurredAt,
            $data['reason'],
            $request->user()
        );

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Shipment status updated.');
    }

    public function storeTracking(
        AppendPurchaseShipmentTrackingRequest $request,
        PurchaseOrder $purchaseOrder,
        PurchaseShipment $purchaseShipment,
        AppendPurchaseShipmentTracking $appendPurchaseShipmentTracking
    ): RedirectResponse {
        $this->assertShipmentBelongsToOrder($purchaseOrder, $purchaseShipment);

        $appendPurchaseShipmentTracking->handle(
            $purchaseShipment,
            $request->validated(),
            $request->user()
        );

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Tracking number added without changing existing tracking history.');
    }

    private function assertShipmentBelongsToOrder(
        PurchaseOrder $purchaseOrder,
        PurchaseShipment $purchaseShipment
    ): void {
        abort_unless(
            (int) $purchaseShipment->purchase_order_id === (int) $purchaseOrder->id,
            404
        );
    }
}
