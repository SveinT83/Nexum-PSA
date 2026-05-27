<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarParticipant;
use Illuminate\Support\Carbon;

class UpdateCalendarEvent
{
    public function handle(CalendarEvent $event, array $data, User $actor): CalendarEvent
    {
        $timezone = $data['timezone'] ?? $event->timezone;

        $event->forceFill([
            'calendar_id' => $data['calendar_id'] ?? $event->calendar_id,
            'title' => $data['title'] ?? $event->title,
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'meeting_url' => $data['meeting_url'] ?? null,
            'starts_at' => isset($data['starts_at']) ? Carbon::parse($data['starts_at'], $timezone)->utc() : $event->starts_at,
            'ends_at' => isset($data['ends_at']) ? Carbon::parse($data['ends_at'], $timezone)->utc() : $event->ends_at,
            'timezone' => $timezone,
            'all_day' => (bool) ($data['all_day'] ?? $event->all_day),
            'status' => $data['status'] ?? $event->status,
            'transparency' => $data['transparency'] ?? $event->transparency,
            'visibility' => $data['visibility'] ?? $event->visibility,
            'updated_by' => $actor->id,
        ])->save();

        if (array_key_exists('participants', $data)) {
            $this->syncParticipants($event, $data['participants']);
        }

        return $event->fresh(['calendar', 'participants']);
    }

    private function syncParticipants(CalendarEvent $event, array|string|null $participants): void
    {
        $event->participants()->delete();

        if (is_string($participants)) {
            $participants = collect(preg_split('/[\n,;]+/', $participants))
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->map(fn ($email) => ['participant_type' => 'email', 'email' => $email, 'name' => $email])
                ->values()
                ->all();
        }

        foreach ($participants ?: [] as $participant) {
            CalendarParticipant::query()->create([
                'event_id' => $event->id,
                'participant_type' => $participant['participant_type'] ?? 'email',
                'participant_id' => $participant['participant_id'] ?? null,
                'name' => $participant['name'] ?? $participant['email'] ?? null,
                'email' => $participant['email'] ?? null,
                'role' => $participant['role'] ?? 'required',
                'response_status' => $participant['response_status'] ?? 'needs_action',
                'notify' => (bool) ($participant['notify'] ?? false),
            ]);
        }
    }
}
