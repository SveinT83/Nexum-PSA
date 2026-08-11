<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canReverse = $this->receipt_type === 'receipt'
            && $this->status === 'posted'
            && $this->resource->relationLoaded('reversal')
            && $this->reversal === null;

        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'purchase_order_id' => $this->purchase_order_id,
            'purchase_shipment_id' => $this->purchase_shipment_id,
            'receipt_type' => $this->receipt_type,
            'status' => $this->status,
            'idempotency_token' => $this->idempotency_token,
            'delivery_note_ref' => $this->delivery_note_ref,
            'received_at' => $this->received_at?->toISOString(),
            'warehouse_id' => $this->warehouse_id,
            'room_id' => $this->room_id,
            'box_id' => $this->box_id,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'lines' => PurchaseReceiptLineResource::collection($this->whenLoaded('lines')),
            'reversal' => $this->whenLoaded('reversal', fn (): ?array => $this->reversal ? [
                'reversal_receipt_id' => $this->reversal->reversal_receipt_id,
                'reason' => $this->reversal->reason,
                'actor_id' => $this->reversal->actor_id,
                'created_at' => $this->reversal->created_at?->toISOString(),
            ] : null),
            'reversal_of' => $this->whenLoaded('reversalOf', fn (): ?array => $this->reversalOf ? [
                'original_receipt_id' => $this->reversalOf->original_receipt_id,
                'reason' => $this->reversalOf->reason,
                'actor_id' => $this->reversalOf->actor_id,
                'created_at' => $this->reversalOf->created_at?->toISOString(),
            ] : null),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'links' => [
                'reverse' => $this->when(
                    $canReverse,
                    route('api.v1.storage.purchase-orders.receipts.reverse', [
                        $this->purchase_order_id,
                        $this->id,
                    ])
                ),
            ],
        ];
    }
}
