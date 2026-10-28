<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Catalog metadata is distinct from guarded draft/version definitions. */
class TicketRuleApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description,
            'is_active' => (bool) $this->is_active, 'weight' => (int) $this->weight, 'stop_processing' => (bool) $this->stop_processing,
            'trigger' => $this->trigger, 'lifecycle_status' => $this->lifecycle_status, 'definition_schema_version' => (int) $this->definition_schema_version,
            'definition_checksum' => $this->definition_checksum, 'compatibility_status' => $this->compatibility_status,
            'compatibility_reason_code' => $this->compatibility_reason_code, 'published_version_id' => $this->published_version_id,
            'published_by' => $this->published_by, 'published_at' => $this->published_at?->toISOString(),
            'draft_checksum' => $this->draft_checksum, 'draft_updated_by' => $this->draft_updated_by, 'draft_updated_at' => $this->draft_updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(), 'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
