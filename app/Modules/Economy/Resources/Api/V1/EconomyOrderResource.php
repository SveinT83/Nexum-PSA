<?php

namespace App\Modules\Economy\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EconomyOrderResource extends JsonResource
{
    /*
    |--------------------------------------------------------------------------
    | Economy order payload
    |--------------------------------------------------------------------------
    |
    | Orders are internal invoice preparation records. They are intentionally
    | exposed with totals, period, state, client context, and line details when
    | loaded, while downstream invoice/export behavior remains outside this API.
    |
    */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'client_id' => $this->client_id,
            'period_start' => optional($this->period_start)->toDateString(),
            'period_end' => optional($this->period_end)->toDateString(),
            'status' => $this->status,
            'subtotal_ex_vat' => (float) $this->subtotal_ex_vat,
            'vat_amount' => (float) $this->vat_amount,
            'total_inc_vat' => (float) $this->total_inc_vat,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'generated_at' => optional($this->generated_at)->toIso8601String(),
            'ready_at' => optional($this->ready_at)->toIso8601String(),
            'approved_at' => optional($this->approved_at)->toIso8601String(),
            'exported_at' => optional($this->exported_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
            ]),
            'lines_count' => $this->whenCounted('lines'),
            'lines' => EconomyOrderLineResource::collection($this->whenLoaded('lines')),
            'links' => [
                'self' => route('api.v1.economy.orders.show', $this->resource),
                'mark_ready' => route('api.v1.economy.orders.ready', $this->resource),
                'mark_draft' => route('api.v1.economy.orders.draft', $this->resource),
            ],
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
