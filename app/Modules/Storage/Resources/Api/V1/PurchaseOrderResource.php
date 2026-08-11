<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $ordered = $this->lineTotal('qty_ordered_total', 'qty_ordered');
        $received = $this->lineTotal('qty_received_total', 'qty_received');
        $cancelled = $this->lineTotal('qty_cancelled_total', 'qty_cancelled');
        $outstanding = $ordered === null || $received === null || $cancelled === null
            ? null
            : max(0, $ordered - $received - $cancelled);
        $active = in_array($this->status, ['ordered', 'partially_received'], true);
        $canCancel = in_array($this->status, ['draft', 'ordered'], true)
            && $this->resource->relationLoaded('lines')
            && $this->resource->relationLoaded('shipments')
            && $this->resource->relationLoaded('receipts')
            && $this->lines->doesntContain(fn ($line): bool => (int) $line->qty_received > 0)
            && $this->shipments->doesntContain(fn ($shipment): bool => $shipment->status !== 'cancelled')
            && $this->receipts->doesntContain(
                fn ($receipt): bool => in_array($receipt->status, ['posted', 'reversed'], true)
            );

        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'vendor_id' => $this->vendor_id,
            'supplier_name_snapshot' => $this->supplier_name_snapshot,
            'deliver_to_warehouse_id' => $this->deliver_to_warehouse_id,
            'status' => $this->status,
            'status_changed_at' => $this->status_changed_at?->toISOString(),
            'status_changed_by' => $this->status_changed_by,
            'closed_at' => $this->closed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'vendor_ref' => $this->vendor_ref,
            'ordered_at' => $this->ordered_at?->toDateString(),
            'expected_at' => $this->expected_at?->toDateString(),
            'currency' => $this->currency,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'progress' => [
                'qty_ordered' => $ordered,
                'qty_received' => $received,
                'qty_cancelled' => $cancelled,
                'qty_outstanding' => $outstanding,
            ],
            'lines_count' => $this->resource->getAttribute('lines_count')
                ?? ($this->resource->relationLoaded('lines') ? $this->lines->count() : null),
            'shipments_count' => $this->resource->getAttribute('shipments_count')
                ?? ($this->resource->relationLoaded('shipments') ? $this->shipments->count() : null),
            'vendor' => $this->whenLoaded('vendor', fn (): ?array => $this->vendor ? [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
                'vendor_code' => $this->vendor->vendor_code,
            ] : null),
            'destination_warehouse' => $this->whenLoaded('deliverToWarehouse', fn (): ?array => $this->deliverToWarehouse ? [
                'id' => $this->deliverToWarehouse->id,
                'name' => $this->deliverToWarehouse->name,
                'code' => $this->deliverToWarehouse->code,
            ] : null),
            'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')),
            'shipments' => PurchaseShipmentResource::collection($this->whenLoaded('shipments')),
            'receipts' => PurchaseReceiptResource::collection($this->whenLoaded('receipts')),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'links' => [
                'self' => route('api.v1.storage.purchase-orders.show', $this->id),
                'update' => $this->when(
                    in_array($this->status, ['draft', 'ordered'], true),
                    route('api.v1.storage.purchase-orders.update', $this->id)
                ),
                'add_shipment' => $this->when($active && ($outstanding ?? 0) > 0,
                    route('api.v1.storage.purchase-orders.shipments.store', $this->id)),
                'post_receipt' => $this->when($active && ($outstanding ?? 0) > 0,
                    route('api.v1.storage.purchase-orders.receipts.store', $this->id)),
                'close' => $this->when($this->status === 'received' && $outstanding === 0,
                    route('api.v1.storage.purchase-orders.close', $this->id)),
                'cancel' => $this->when($canCancel,
                    route('api.v1.storage.purchase-orders.cancel', $this->id)),
            ],
        ];
    }

    private function lineTotal(string $aggregate, string $column): ?int
    {
        $value = $this->resource->getAttribute($aggregate);
        if ($value !== null) {
            return (int) $value;
        }

        return $this->resource->relationLoaded('lines') ? (int) $this->lines->sum($column) : null;
    }
}
