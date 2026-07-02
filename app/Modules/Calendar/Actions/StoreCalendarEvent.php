<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventSeries;
use App\Modules\Calendar\Models\CalendarParticipant;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class StoreCalendarEvent
{
    public function __construct(private readonly ResolveWorkContext $workContexts)
    {
    }

    public function handle(array $data, User $actor): CalendarEvent
    {
        $timezone = $data['timezone'] ?? 'Europe/Oslo';

        $startsAt = Carbon::parse($data['starts_at'], $timezone);
        $endsAt = Carbon::parse($data['ends_at'], $timezone);
        $series = $this->createSeries($data, $startsAt, $endsAt, $timezone);

        $event = CalendarEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'calendar_id' => $data['calendar_id'],
            'work_context_id' => $data['work_context_id'] ?? $this->workContexts->internal()->id,
            'series_id' => $series?->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'meeting_url' => $data['meeting_url'] ?? null,
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $endsAt->copy()->utc(),
            'timezone' => $timezone,
            'all_day' => (bool) ($data['all_day'] ?? false),
            'status' => $data['status'] ?? 'confirmed',
            'transparency' => $data['transparency'] ?? 'busy',
            'visibility' => $data['visibility'] ?? 'default',
            'priority' => $data['priority'] ?? null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'source' => $data['source'] ?? 'local',
            'metadata' => Arr::get($data, 'metadata'),
        ]);

        $this->syncParticipants($event, $data['participants'] ?? []);

        return $event->load(['calendar', 'participants']);
    }

    private function createSeries(array $data, Carbon $startsAt, Carbon $endsAt, string $timezone): ?CalendarEventSeries
    {
        $frequency = $data['recurrence_frequency'] ?? 'none';

        if (! in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            return null;
        }

        $recurrenceEndsAt = filled($data['recurrence_ends_at'] ?? null)
            ? Carbon::parse($data['recurrence_ends_at'], $timezone)->endOfDay()
            : $startsAt->copy()->addMonths(6)->endOfDay();

        return CalendarEventSeries::query()->create([
            'uuid' => (string) Str::uuid(),
            'calendar_id' => $data['calendar_id'],
            'timezone' => $timezone,
            'rrule' => 'FREQ='.strtoupper($frequency),
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $endsAt->copy()->utc(),
            'recurrence_starts_at' => $startsAt->copy()->utc(),
            'recurrence_ends_at' => $recurrenceEndsAt->copy()->utc(),
            'max_occurrences' => 200,
            'metadata' => ['frequency' => $frequency],
        ]);
    }

    private function syncParticipants(CalendarEvent $event, array|string|null $participants): void
    {
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
