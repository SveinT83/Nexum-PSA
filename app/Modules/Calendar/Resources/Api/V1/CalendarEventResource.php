<?php

namespace App\Modules\Calendar\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'calendar_id' => $this->calendar_id,
            'work_context_id' => $this->work_context_id,
            'series_id' => $this->series_id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'meeting_url' => $this->meeting_url,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'timezone' => $this->timezone,
            'all_day' => $this->all_day,
            'status' => $this->status,
            'transparency' => $this->transparency,
            'visibility' => $this->visibility,
            'priority' => $this->priority,
            'source' => $this->source,
            'external_source' => $this->external_source,
            'external_calendar_id' => $this->external_calendar_id,
            'external_event_id' => $this->external_event_id,
            'external_uid' => $this->external_uid,
            'sync_status' => $this->sync_status,
            'calendar' => $this->whenLoaded('calendar', fn () => [
                'id' => $this->calendar?->id,
                'name' => $this->calendar?->name,
                'color' => $this->calendar?->color,
            ]),
            'work_context' => $this->whenLoaded('workContext', fn () => [
                'id' => $this->workContext?->id,
                'type' => $this->workContext?->type,
                'name' => $this->workContext?->name,
            ]),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->map(fn ($participant) => [
                'id' => $participant->id,
                'participant_type' => $participant->participant_type,
                'participant_id' => $participant->participant_id,
                'name' => $participant->name,
                'email' => $participant->email,
                'role' => $participant->role,
                'response_status' => $participant->response_status,
                'notify' => $participant->notify,
            ])->values()),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.calendar.events.show', $this->id),
            ],
        ];
    }
}
