<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Definitions are exposed only after the controller validates every Custom Field target for this actor. */
class TicketRuleVersionApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'ticket_rule_id' => $this->ticket_rule_id, 'version_number' => (int) $this->version_number,
            'status' => $this->status, 'schema_version' => (int) $this->definition_schema_version,
            'name' => $this->name, 'description' => $this->description, 'definition' => app(\App\Modules\Ticket\Services\TicketRuleApiDefinition::class)->project((array) $this->definition_json),
            'definition_checksum' => $this->definition_checksum, 'published_by' => $this->published_by,
            'published_at' => $this->published_at?->toISOString(), 'provenance' => $this->provenance];
    }
}
