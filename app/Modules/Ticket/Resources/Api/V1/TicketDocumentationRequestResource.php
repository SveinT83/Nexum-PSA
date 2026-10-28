<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Follow-up status never implies that Knowledge or BookStack publication completed. */
class TicketDocumentationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'ticket_id' => $this->ticket_id, 'request_event_id' => $this->request_event_id, 'requested_by' => $this->requested_by,
            'status' => $this->status, 'reason' => $this->reason, 'article_id' => $this->article_id, 'revision_id' => $this->revision_id,
            'completed_at' => $this->completed_at?->toISOString(), 'publication_read_back_at' => $this->publication_read_back_at?->toISOString(),
            'failure_code' => $this->failure_code, 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
