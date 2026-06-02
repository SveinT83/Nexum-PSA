<?php

namespace App\Modules\Economy\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EconomyOrderLineResource extends JsonResource
{
    /*
    |--------------------------------------------------------------------------
    | Economy order line payload
    |--------------------------------------------------------------------------
    |
    | Lines are generated from ticket time and ticket cost sources. The API keeps
    | source metadata visible so external agents can trace invoice preparation
    | back to the originating operational record.
    |
    */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'economy_order_id' => $this->economy_order_id,
            'client_id' => $this->client_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'ticket_id' => $this->ticket_id,
            'work_date' => optional($this->work_date)->toDateString(),
            'line_type' => $this->line_type,
            'description' => $this->description,
            'quantity' => $this->quantity === null ? null : (float) $this->quantity,
            'unit' => $this->unit,
            'unit_price_ex_vat' => $this->unit_price_ex_vat === null ? null : (float) $this->unit_price_ex_vat,
            'line_total_ex_vat' => (float) $this->line_total_ex_vat,
            'vat_rate' => $this->vat_rate === null ? null : (float) $this->vat_rate,
            'vat_amount' => $this->vat_amount === null ? null : (float) $this->vat_amount,
            'total_inc_vat' => (float) $this->total_inc_vat,
            'currency' => $this->currency,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'ticket' => $this->whenLoaded('ticket', fn () => [
                'id' => $this->ticket?->id,
                'ticket_key' => $this->ticket?->ticket_key,
                'subject' => $this->ticket?->subject,
            ]),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
