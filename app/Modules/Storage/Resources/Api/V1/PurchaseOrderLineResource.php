<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class PurchaseOrderLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canReadTickets = $request->user()?->tokenCan('tickets.read') === true;
        $metadata = $this->metadata;
        if (! $canReadTickets && is_array($metadata)) {
            $metadata = Arr::except($metadata, ['ticket_key', 'approved_quote_version_id']);
        }

        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'item_id' => $this->item_id,
            'item_name_snapshot' => $this->item_name_snapshot,
            'sku_snapshot' => $this->sku_snapshot,
            'supplier_sku_snapshot' => $this->supplier_sku_snapshot,
            'ticket_id' => $this->when($canReadTickets, $this->ticket_id),
            'ticket_planned_line_id' => $this->when($canReadTickets, $this->ticket_planned_line_id),
            'qty_ordered' => (int) $this->qty_ordered,
            'qty_received' => (int) $this->qty_received,
            'qty_cancelled' => (int) $this->qty_cancelled,
            'qty_outstanding' => (int) $this->qty_outstanding,
            'unit_cost' => $this->unit_cost === null ? null : (float) $this->unit_cost,
            'tax_rate' => $this->tax_rate === null ? null : (float) $this->tax_rate,
            'currency' => $this->currency,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancelled_by' => $this->cancelled_by,
            'expected_at' => $this->expected_at?->toDateString(),
            'metadata' => $metadata,
            'item' => $this->whenLoaded('item', fn (): ?array => $this->item ? [
                'id' => $this->item->id,
                'sku' => $this->item->sku,
                'name' => $this->item->name,
                'has_serials' => (bool) $this->item->has_serials,
                'track_batch' => (bool) $this->item->track_batch,
                'expiry_enabled' => (bool) $this->item->expiry_enabled,
                'warehouse_id' => $this->item->warehouse_id,
            ] : null),
            'ticket' => $this->when(
                $canReadTickets && $this->resource->relationLoaded('ticket'),
                fn (): ?array => $this->ticket ? [
                    'id' => $this->ticket->id,
                    'ticket_key' => $this->ticket->ticket_key,
                    'subject' => $this->ticket->subject,
                ] : null
            ),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
