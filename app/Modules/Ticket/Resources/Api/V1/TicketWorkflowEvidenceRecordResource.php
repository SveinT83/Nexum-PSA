<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** Explicit operational read-back excludes arbitrary snapshots, fingerprints and public-access tokens. */
final class TicketWorkflowEvidenceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = Arr::only($this->resource->attributesToArray(), ['id', 'ticket_id', 'evidence_type', 'scope_key', 'source_type', 'source_id', 'subject_name', 'evidenced_at', 'created_by', 'invalidated_at', 'invalidated_by', 'invalidation_reason', 'comment', 'created_at', 'updated_at']);

        return $result;
    }
}
