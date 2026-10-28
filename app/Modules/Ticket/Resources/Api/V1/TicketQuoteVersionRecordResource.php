<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** Explicit operational read-back excludes arbitrary snapshots, fingerprints and public-access tokens. */
final class TicketQuoteVersionRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = Arr::only($this->resource->attributesToArray(), ['id', 'quote_id', 'source_template_id', 'version_number', 'status', 'approval_status', 'approval_requested_at', 'approval_requested_by', 'approval_decided_at', 'approval_decided_by', 'approval_decision_note', 'title', 'intro_text', 'scope_text', 'assumptions_text', 'exclusions_text', 'next_steps_text', 'internal_note', 'expires_at', 'subtotal_ex_vat', 'discount_total_ex_vat', 'vat_total', 'total_ex_vat', 'total_inc_vat', 'margin_amount', 'margin_percent', 'sent_at', 'viewed_at', 'accepted_at', 'accepted_by_name', 'accepted_method', 'accepted_by_user_id', 'accepted_ticket_message_id', 'rejected_at', 'declined_at', 'declined_by_name', 'declined_reason', 'created_by', 'updated_by', 'created_at', 'updated_at']);
        $result['lines'] = TicketQuoteLineRecordResource::collection($this->whenLoaded('lines'))->resolve($request);

        return $result;
    }
}
