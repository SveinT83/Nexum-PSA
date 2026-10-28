<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields prevent storage paths, arbitrary metadata and coupled records from escaping. */
#[OA\Schema(schema: 'TicketTimeEntryReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'ticket_id', type: 'integer', nullable: true), new OA\Property(property: 'task_id', type: 'integer', nullable: true), new OA\Property(property: 'user_id', type: 'integer', nullable: true), new OA\Property(property: 'type', type: 'string', nullable: true), new OA\Property(property: 'work_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'ended_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'minutes', type: 'integer', nullable: true), new OA\Property(property: 'cost_account', type: 'string', nullable: true), new OA\Property(property: 'note', type: 'string', nullable: true), new OA\Property(property: 'billable', type: 'boolean', nullable: true), new OA\Property(property: 'billing_status', type: 'string', nullable: true), new OA\Property(property: 'timebank_status', type: 'string', nullable: true), new OA\Property(property: 'billing_basis', type: 'string', nullable: true), new OA\Property(property: 'invoice_text', type: 'string', nullable: true), new OA\Property(property: 'contract_id', type: 'integer', nullable: true), new OA\Property(property: 'contract_item_id', type: 'integer', nullable: true), new OA\Property(property: 'contract_item_time_rate_id', type: 'integer', nullable: true), new OA\Property(property: 'time_rate_id', type: 'integer', nullable: true), new OA\Property(property: 'rate_name', type: 'string', nullable: true), new OA\Property(property: 'rate_code', type: 'string', nullable: true), new OA\Property(property: 'rate_type', type: 'string', nullable: true), new OA\Property(property: 'rate_unit', type: 'string', nullable: true), new OA\Property(property: 'rate_amount_ex_vat', type: 'string', nullable: true), new OA\Property(property: 'rate_currency', type: 'string', nullable: true), new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true)])]
final class TicketTimeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->attributesToArray(), ['id', 'ticket_id', 'task_id', 'user_id', 'type', 'work_date', 'started_at', 'ended_at', 'minutes', 'cost_account', 'note', 'billable', 'billing_status', 'timebank_status', 'billing_basis', 'invoice_text', 'contract_id', 'contract_item_id', 'contract_item_time_rate_id', 'time_rate_id', 'rate_name', 'rate_code', 'rate_type', 'rate_unit', 'rate_amount_ex_vat', 'rate_currency', 'created_at', 'updated_at']);
    }
}
