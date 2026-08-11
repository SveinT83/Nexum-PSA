<?php

namespace App\Modules\Calendar\Resources\Api\V1;

use App\Modules\Calendar\Services\CalendarOwnershipMetadata;
use App\Modules\Calendar\Services\CalendarVisibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $detailsVisible = $viewer !== null
            && app(CalendarVisibility::class)->canViewPrivateDetails($viewer, $this->resource);
        $ownership = app(CalendarOwnershipMetadata::class)->forCalendar($this->calendar, $viewer);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'calendar_id' => $this->calendar_id,
            'work_context_id' => $this->work_context_id,
            'series_id' => $this->series_id,
            'title' => $detailsVisible ? $this->title : 'Busy',
            'description' => $detailsVisible ? $this->description : null,
            'location' => $detailsVisible ? $this->location : null,
            'meeting_url' => $detailsVisible ? $this->meeting_url : null,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'timezone' => $this->timezone,
            'all_day' => $this->all_day,
            'status' => $this->status,
            'transparency' => $this->transparency,
            'visibility' => $this->visibility,
            'is_private' => $this->isPrivate(),
            'details_visible' => $detailsVisible,
            'priority' => $detailsVisible ? $this->priority : null,
            'source' => $detailsVisible ? $this->source : null,
            'external_source' => $detailsVisible ? $this->external_source : null,
            'external_calendar_id' => $detailsVisible ? $this->external_calendar_id : null,
            'external_event_id' => $detailsVisible ? $this->external_event_id : null,
            'external_uid' => $detailsVisible ? $this->external_uid : null,
            'sync_status' => $detailsVisible ? $this->sync_status : null,
            'calendar_color' => $ownership['calendar_color'],
            'calendar_type' => $ownership['calendar_type'],
            'calendar_type_label' => $ownership['calendar_type_label'],
            'owner_kind' => $ownership['owner_kind'],
            'owner_id' => $ownership['owner_id'],
            'owner_label' => $ownership['owner_label'],
            'owner_initials' => $ownership['owner_initials'],
            'owner_badge' => $ownership['owner_badge'],
            'ownership_group' => $ownership['ownership_group'],
            'is_owned_by_viewer' => $ownership['is_owned_by_viewer'],
            'calendar' => $this->whenLoaded('calendar', fn () => [
                'id' => $this->calendar?->id,
                'name' => $this->calendar?->name,
                'color' => $ownership['calendar_color'],
                'type' => $ownership['calendar_type'],
                'type_label' => $ownership['calendar_type_label'],
                'owner_kind' => $ownership['owner_kind'],
                'owner_id' => $ownership['owner_id'],
                'owner_label' => $ownership['owner_label'],
                'owner_badge' => $ownership['owner_badge'],
                'ownership_group' => $ownership['ownership_group'],
                'is_owned_by_viewer' => $ownership['is_owned_by_viewer'],
            ]),
            'work_context' => $this->whenLoaded('workContext', fn () => [
                'id' => $this->workContext?->id,
                'type' => $this->workContext?->type,
                'name' => $detailsVisible ? $this->workContext?->name : null,
            ]),
            'participants' => $this->whenLoaded('participants', fn () => $detailsVisible
                ? $this->participants->map(fn ($participant) => [
                    'id' => $participant->id,
                    'participant_type' => $participant->participant_type,
                    'participant_id' => $participant->participant_id,
                    'name' => $participant->name,
                    'email' => $participant->email,
                    'role' => $participant->role,
                    'response_status' => $participant->response_status,
                    'notify' => $participant->notify,
                ])->values()
                : []),
            'created_by' => $detailsVisible ? $this->created_by : null,
            'updated_by' => $detailsVisible ? $this->updated_by : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.calendar.events.show', $this->id),
            ],
        ];
    }
}
