<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FindAvailableSlots
{
    public function __construct(private CheckAvailability $availability) {}

    /**
     * Find bookable slots inside a user's stored rules or explicit weekly windows.
     *
     * Explicit windows let a consuming domain apply an approved availability
     * policy while Calendar remains the owner of personal calendars and busy
     * event conflict checks.
     *
     * @param  array{timezone?: string, weekdays?: array<int, array<int, array{start: string, end: string}>>}|null  $availabilityWindows
     */
    public function handle(
        User $user,
        Carbon $rangeStartsAt,
        Carbon $rangeEndsAt,
        int $durationMinutes,
        int $limit = 20,
        ?array $availabilityWindows = null,
    ): Collection {
        $calendar = Calendar::query()
            ->where('type', 'personal')
            ->where('owner_type', $user::class)
            ->where('owner_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $calendar) {
            return collect();
        }

        $timezone = $availabilityWindows['timezone'] ?? ($calendar->timezone ?: 'Europe/Oslo');
        $rules = $availabilityWindows === null
            ? CalendarAvailabilityRule::query()
                ->where(function ($query) use ($calendar, $user) {
                    $query->where('calendar_id', $calendar->id)
                        ->orWhere('user_id', $user->id);
                })
                ->get()
                ->groupBy('weekday')
            : $this->normalizeExplicitWindows($availabilityWindows);

        $slots = collect();
        $cursorDay = $rangeStartsAt->copy()->timezone($timezone)->startOfDay();
        $endDay = $rangeEndsAt->copy()->timezone($timezone)->startOfDay();

        while ($cursorDay->lte($endDay) && $slots->count() < $limit) {
            $weekday = (int) $cursorDay->dayOfWeekIso;

            foreach ($rules->get($weekday, collect()) as $rule) {
                $startsAtLocal = is_array($rule) ? ($rule['start'] ?? null) : $rule->starts_at_local;
                $endsAtLocal = is_array($rule) ? ($rule['end'] ?? null) : $rule->ends_at_local;

                if (! $startsAtLocal || ! $endsAtLocal || $startsAtLocal >= $endsAtLocal) {
                    continue;
                }

                $windowStart = Carbon::parse($cursorDay->toDateString().' '.$startsAtLocal, $timezone);
                $windowEnd = Carbon::parse($cursorDay->toDateString().' '.$endsAtLocal, $timezone);
                $slot = $windowStart->copy()->max($rangeStartsAt->copy()->timezone($timezone));

                while ($slot->copy()->addMinutes($durationMinutes)->lte($windowEnd) && $slot->lt($rangeEndsAt->copy()->timezone($timezone)) && $slots->count() < $limit) {
                    $slotEnd = $slot->copy()->addMinutes($durationMinutes);

                    if ($this->availability->isFree([$calendar], $slot->copy(), $slotEnd->copy())) {
                        $slots->push([
                            'calendar' => $calendar,
                            'starts_at' => $slot->copy(),
                            'ends_at' => $slotEnd->copy(),
                            'timezone' => $timezone,
                        ]);
                    }

                    $slot->addMinutes(15);
                }
            }

            $cursorDay->addDay();
        }

        return $slots;
    }

    /**
     * @param  array{weekdays?: array<int, array<int, array{start: string, end: string}>>}  $availabilityWindows
     */
    private function normalizeExplicitWindows(array $availabilityWindows): Collection
    {
        return collect($availabilityWindows['weekdays'] ?? [])
            ->mapWithKeys(function (mixed $windows, int|string $weekday): array {
                $normalized = collect(is_array($windows) ? $windows : [])
                    ->filter(fn (mixed $window): bool => is_array($window))
                    ->map(fn (array $window): array => [
                        'start' => substr((string) ($window['start'] ?? ''), 0, 5),
                        'end' => substr((string) ($window['end'] ?? ''), 0, 5),
                    ])
                    ->values();

                return [(int) $weekday => $normalized];
            });
    }
}
