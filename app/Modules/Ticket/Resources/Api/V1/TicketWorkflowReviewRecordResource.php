<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** Explicit operational read-back excludes arbitrary snapshots, fingerprints and public-access tokens. */
final class TicketWorkflowReviewRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = Arr::only($this->resource->attributesToArray(), ['id', 'ticket_id', 'workflow_version_id', 'state_key', 'gate_key', 'status', 'requested_by', 'assigned_reviewer_id', 'reviewed_by', 'comment', 'decided_at', 'invalidated_at', 'invalidation_reason', 'created_at', 'updated_at']);

        return $result;
    }
}
