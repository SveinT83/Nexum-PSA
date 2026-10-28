<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields prevent storage paths, arbitrary metadata and coupled records from escaping. */
#[OA\Schema(schema: 'TicketCostEntryReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'ticket_id', type: 'integer', nullable: true), new OA\Property(property: 'user_id', type: 'integer', nullable: true), new OA\Property(property: 'storage_item_id', type: 'integer', nullable: true), new OA\Property(property: 'storage_reservation_id', type: 'integer', nullable: true), new OA\Property(property: 'quantity', type: 'integer', nullable: true), new OA\Property(property: 'item_name', type: 'string', nullable: true), new OA\Property(property: 'item_sku', type: 'string', nullable: true), new OA\Property(property: 'unit_price_ex_vat', type: 'string', nullable: true), new OA\Property(property: 'currency', type: 'string', nullable: true), new OA\Property(property: 'status', type: 'string', nullable: true), new OA\Property(property: 'billing_status', type: 'string', nullable: true), new OA\Property(property: 'invoice_text', type: 'string', nullable: true), new OA\Property(property: 'note', type: 'string', nullable: true), new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true)])]
final class TicketCostEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->attributesToArray(), ['id', 'ticket_id', 'user_id', 'storage_item_id', 'storage_reservation_id', 'quantity', 'item_name', 'item_sku', 'unit_price_ex_vat', 'currency', 'status', 'billing_status', 'invoice_text', 'note', 'created_at', 'updated_at']);
    }
}
