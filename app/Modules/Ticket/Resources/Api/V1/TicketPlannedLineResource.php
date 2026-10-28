<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields prevent storage paths, arbitrary metadata and coupled records from escaping. */
#[OA\Schema(schema: 'TicketPlannedLineReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'ticket_id', type: 'integer', nullable: true), new OA\Property(property: 'line_type', type: 'string', nullable: true), new OA\Property(property: 'source_type', type: 'string', nullable: true), new OA\Property(property: 'source_id', type: 'integer', nullable: true), new OA\Property(property: 'storage_item_id', type: 'integer', nullable: true), new OA\Property(property: 'section', type: 'string', nullable: true), new OA\Property(property: 'downstream_type', type: 'string', nullable: true), new OA\Property(property: 'sku', type: 'string', nullable: true), new OA\Property(property: 'name', type: 'string', nullable: true), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'quantity', type: 'string', nullable: true), new OA\Property(property: 'unit', type: 'string', nullable: true), new OA\Property(property: 'unit_cost_ex_vat', type: 'string', nullable: true), new OA\Property(property: 'unit_price_ex_vat', type: 'string', nullable: true), new OA\Property(property: 'vat_rate', type: 'string', nullable: true), new OA\Property(property: 'status', type: 'string', nullable: true), new OA\Property(property: 'approved_quote_version_id', type: 'integer', nullable: true), new OA\Property(property: 'converted_cost_entry_id', type: 'integer', nullable: true), new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true)])]
final class TicketPlannedLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->attributesToArray(), ['id', 'ticket_id', 'line_type', 'source_type', 'source_id', 'storage_item_id', 'section', 'downstream_type', 'sku', 'name', 'description', 'quantity', 'unit', 'unit_cost_ex_vat', 'unit_price_ex_vat', 'vat_rate', 'status', 'approved_quote_version_id', 'converted_cost_entry_id', 'created_at', 'updated_at']);
    }
}
