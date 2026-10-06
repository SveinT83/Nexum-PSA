<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventException;
use App\Modules\Calendar\Services\CalendarRecurrenceExpander;
use App\Modules\Calendar\Services\CalendarVisibility;
use App\Modules\Calendar\Support\WorkPlanLocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageWorkPlanBlock
{
    public function __construct(
        private StoreCalendarEvent $store,
        private UpdateCalendarEvent $update,
        private CalendarRecurrenceExpander $recurrence,
        private CalendarVisibility $visibility,
    ) {}

    /** Serialize on the worker and return a stable UUID on duplicate create requests. */
    public function create(User $worker, array $input): CalendarEvent
    {
        $data = $this->validate($input);

        return DB::transaction(function () use ($worker, $data) {
            $worker->newQuery()->whereKey($worker->id)->lockForUpdate()->firstOrFail();
            $existing = CalendarEvent::withTrashed()->where('uuid', $data['request_id'])->first();
            if ($existing) {
                abort_unless((int) $existing->created_by === (int) $worker->id
                    && $existing->source === 'work_plan'
                    && data_get($existing->metadata, 'create_fingerprint') === $this->fingerprint($data), 409,
                    'This request ID has already been used with different data.');
                $this->authorize($worker, $existing, 'calendar.create');

                return $existing;
            }
            $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($worker);
            abort_unless($worker->can('calendar.create') && $this->visibility->canManageCalendar($worker, $calendar), 403);
            $event = $this->store->handle($this->payload($data, $calendar->id), $worker);
            $event->update([
                'uuid' => $data['request_id'],
                'metadata' => $this->metadata($data) + ['version' => 1, 'create_fingerprint' => $this->fingerprint($data)],
            ]);

            return $event->fresh();
        });
    }

    /** An occurrence edit cancels only that occurrence and creates one linked replacement. */
    public function change(User $worker, CalendarEvent $event, array $input, bool $cancel = false): CalendarEvent
    {
        $control = Validator::make($input, [
            'version' => ['required', 'integer', 'min:1'],
            'scope' => ['required', Rule::in(['event', 'series'])],
            'occurrence_starts_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
        ])->validate();
        $data = $cancel ? [] : $this->validate($input);

        return DB::transaction(function () use ($worker, $event, $control, $data, $cancel) {
            $worker->newQuery()->whereKey($worker->id)->lockForUpdate()->firstOrFail();
            $event = CalendarEvent::query()->lockForUpdate()->findOrFail($event->id);
            $this->authorize($worker, $event, $cancel ? 'calendar.delete' : 'calendar.update');
            abort_unless((int) data_get($event->metadata, 'version', 1) === (int) $control['version'], 409,
                'The block changed. Reload before editing it.');
            abort_if($event->status === 'cancelled', 409, 'This block is cancelled.');
            if ($event->series_id && $control['scope'] === 'event') {
                if (empty($control['occurrence_starts_at'])) {
                    throw ValidationException::withMessages(['occurrence_starts_at' => 'Select the occurrence to change.']);
                }
                $original = Carbon::parse($control['occurrence_starts_at'])->utc();
                $occurrence = $this->recurrence->expand($event->series()->with(['events', 'exceptions'])->first(),
                    $original->copy()->subSecond(), $original->copy()->addSecond())
                    ->first(fn ($row) => $row['starts_at']->equalTo($original));
                abort_unless($occurrence, 409, 'This occurrence no longer exists.');
                $replacement = null;
                if (! $cancel) {
                    $data['recurrence_frequency'] = 'none';
                    $replacement = $this->store->handle($this->payload($data, $event->calendar_id), $worker);
                    $replacement->update(['metadata' => $this->metadata($data) + [
                        'version' => 1, 'parent_event_id' => $event->id,
                    ]]);
                }
                CalendarEventException::create([
                    'series_id' => $event->series_id,
                    'original_starts_at' => $original,
                    'exception_type' => $cancel ? 'cancelled' : 'modified',
                    'replacement_event_id' => $replacement?->id,
                ]);
                $event->update(['metadata' => array_merge($event->metadata ?? [],
                    ['version' => $control['version'] + 1])]);

                return ($replacement ?? $event)->fresh();
            }
            if ($cancel) {
                $event->update(['status' => 'cancelled']);
            } else {
                // Recurrence shape is explicit; do not silently convert a single block into a series.
                $frequency = data_get($event->series?->metadata, 'frequency', 'none');
                if ($data['recurrence_frequency'] !== $frequency) {
                    throw ValidationException::withMessages(['recurrence_frequency' => 'Cancel this block and create a new one to change its recurrence frequency.']);
                }
                $this->update->handle($event, $this->payload($data, $event->calendar_id), $worker);
                if ($event->series_id) {
                    $event->series->update([
                        'starts_at' => $event->fresh()->starts_at, 'ends_at' => $event->fresh()->ends_at,
                        'timezone' => $data['timezone'], 'recurrence_starts_at' => $event->fresh()->starts_at,
                        'recurrence_ends_at' => Carbon::parse($data['recurrence_ends_at'], $data['timezone'])->endOfDay()->utc(),
                    ]);
                }
            }
            $event->update(['metadata' => array_merge($event->metadata ?? [],
                $cancel ? [] : $this->metadata($data), ['version' => $control['version'] + 1])]);

            return $event->fresh();
        });
    }

    public function authorize(User $worker, CalendarEvent $event, string $permission): void
    {
        abort_unless($event->source === 'work_plan' && $event->calendar
            && $event->calendar->owner_type === $worker::class
            && (int) $event->calendar->owner_id === (int) $worker->id
            && $worker->can($permission)
            && $this->visibility->canManageCalendar($worker, $event->calendar), 403);
    }

    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'request_id' => ['required', 'uuid'],
            'activity' => ['required', Rule::in(['education', 'work', 'other'])],
            'title' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'phone_duty_available' => ['required', 'boolean'],
            'blocks_booking' => ['required', 'boolean'],
            'recurrence_frequency' => ['required', Rule::in(['none', 'weekly'])],
            'recurrence_ends_at' => ['nullable', 'required_unless:recurrence_frequency,none', 'date_format:Y-m-d'],
        ])->validate();
        $start = WorkPlanLocalTime::parse($data['starts_at'], $data['timezone'], 'starts_at');
        $end = WorkPlanLocalTime::parse($data['ends_at'], $data['timezone'], 'ends_at');
        if ($end->lte($start) || $start->diffInHours($end) > 24) {
            throw ValidationException::withMessages(['ends_at' => 'A block must end after its start and last at most 24 hours.']);
        }
        if ($data['recurrence_frequency'] !== 'none') {
            $until = Carbon::parse($data['recurrence_ends_at'], $data['timezone'])->endOfDay();
            if ($until->lt($end) || $until->gt($start->copy()->addYear())) {
                throw ValidationException::withMessages(['recurrence_ends_at' => 'Choose an end date within one year and after the first block.']);
            }
            $dayOffset = (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay());
            for ($cursor = $start->copy()->startOfDay(); $cursor->lte($until); $cursor->addWeek()) {
                WorkPlanLocalTime::parse($cursor->toDateString().' '.$start->format('H:i'), $data['timezone'], 'starts_at');
                WorkPlanLocalTime::parse($cursor->copy()->addDays($dayOffset)->toDateString().' '.$end->format('H:i'), $data['timezone'], 'ends_at');
            }
        }

        return $data;
    }

    private function payload(array $data, int $calendar): array
    {
        return [
            'calendar_id' => $calendar, 'title' => $data['title'],
            'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'timezone' => $data['timezone'],
            'status' => 'confirmed', 'visibility' => 'default',
            'transparency' => $data['blocks_booking'] ? 'busy' : 'free',
            'source' => 'work_plan', 'metadata' => $this->metadata($data),
            'recurrence_frequency' => $data['recurrence_frequency'],
            'recurrence_ends_at' => $data['recurrence_ends_at'] ?? null,
        ];
    }

    private function metadata(array $data): array
    {
        return ['activity' => $data['activity'], 'phone_duty_available' => (bool) $data['phone_duty_available']];
    }

    private function fingerprint(array $data): string
    {
        // Validation fixes the field order and prevents caller metadata from taking ownership.
        return hash('sha256', json_encode($data));
    }
}
