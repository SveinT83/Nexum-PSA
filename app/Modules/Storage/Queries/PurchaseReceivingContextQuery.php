<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Browser forms and API receiving use one lifecycle/placement/shipment context. */
class PurchaseReceivingContextQuery
{
    public function receive(Request $request, PurchaseOrder $purchaseOrder): array
    {
        $this->assertReceivable($purchaseOrder);

        $purchaseOrder->load([
            'vendor',
            'deliverToWarehouse',
            'lines.item',
            'shipments.lines',
            'shipments.trackings',
        ]);

        $selectedShipment = $this->selectedShipment($request, $purchaseOrder);

        return [
            'purchaseOrder' => $purchaseOrder,
            'selectedShipment' => $selectedShipment,
            'rooms' => Room::query()
                ->where('warehouse_id', $purchaseOrder->deliver_to_warehouse_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'boxes' => Box::query()
                ->where('warehouse_id', $purchaseOrder->deliver_to_warehouse_id)
                ->where('is_active', true)
                ->orderBy('code_human')
                ->get(),
            'idempotencyToken' => (string) Str::uuid(),
            'canReceiveOverage' => $request->user()->can('storage.purchase_receive_overage'),
        ];
    }

    public function controlSlip(Request $request, PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->load([
            'vendor',
            'deliverToWarehouse',
            'lines.item',
            'shipments.lines',
            'shipments.trackings',
        ]);

        return [
            'purchaseOrder' => $purchaseOrder,
            'selectedShipment' => $this->selectedShipment($request, $purchaseOrder),
        ];
    }

    private function assertReceivable(PurchaseOrder $purchaseOrder): void
    {
        abort_unless(in_array($purchaseOrder->status, [
            PurchaseOrder::STATUS_ORDERED,
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        ], true), 409, 'This purchase order is not open for receiving.');

        abort_unless(
            $purchaseOrder->lines()->get()->sum('qty_outstanding') > 0,
            409,
            'This purchase order has no outstanding quantity.'
        );
    }

    private function selectedShipment(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (! $request->filled('shipment_id')) {
            return null;
        }

        $shipment = $purchaseOrder->shipments
            ->firstWhere('id', $request->integer('shipment_id'));

        abort_unless($shipment, 404);

        abort_if(
            $shipment->status === PurchaseShipment::STATUS_CANCELLED,
            409,
            'Cancelled shipments cannot be selected for receiving.'
        );

        return $shipment;
    }
}
