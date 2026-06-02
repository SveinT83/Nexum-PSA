<?php

namespace App\Modules\Calendar\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'description' => $this->description,
            'color' => $this->color,
            'timezone' => $this->timezone,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'is_visible_by_default' => $this->is_visible_by_default,
            'visibility_default' => $this->visibility_default,
            'transparency_default' => $this->transparency_default,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'events' => route('api.v1.calendar.events.index', ['calendar_id' => $this->id]),
            ],
        ];
    }
}
