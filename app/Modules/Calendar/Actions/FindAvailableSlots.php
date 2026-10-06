<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarAvailabilityOverride;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\Calendar\Support\WorkPlanLocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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

        $overrides = CalendarAvailabilityOverride::query()->where('calendar_id', $calendar->id)
            ->whereBetween('date', [$rangeStartsAt->copy()->subDay()->toDateString(), $rangeEndsAt->copy()->addDay()->toDateString()])
            ->get()->groupBy(fn ($override) => $override->date->toDateString());
        $slots = collect();
        $cursorDay = $rangeStartsAt->copy()->timezone($timezone)->startOfDay()->subDay();
        $endDay = $rangeEndsAt->copy()->timezone($timezone)->startOfDay();

        while ($cursorDay->lte($endDay) && $slots->count() < $limit) {
            $weekday = (int) $cursorDay->dayOfWeekIso;

            $dayRules = $rules->get($weekday, collect());
            if ($availabilityWindows === null) {
                $dayRules = \App\Modules\Calendar\Support\EffectiveWorkPlanRules::forDate($dayRules, $cursorDay->toDateString());
            }
            foreach ($dayRules as $rule) {
                if (! is_array($rule) && data_get($rule->metadata, 'enabled', true) === false) {
                    continue;
                }
                $startsAtLocal = is_array($rule) ? ($rule['start'] ?? null) : $rule->starts_at_local;
                $endsAtLocal = is_array($rule) ? ($rule['end'] ?? null) : $rule->ends_at_local;

                if (! $startsAtLocal || ! $endsAtLocal || $startsAtLocal === $endsAtLocal) {
                    continue;
                }

                $ruleTimezone = is_array($rule) ? $timezone : ($rule->timezone ?: $timezone);
                $endDayLocal = $cursorDay->copy();
                if ($endsAtLocal < $startsAtLocal) {
                    $endDayLocal->addDay();
                }
                try {
                    $windowStart = WorkPlanLocalTime::parse($cursorDay->toDateString().' '.substr($startsAtLocal, 0, 5), $ruleTimezone, 'start');
                    $windowEnd = WorkPlanLocalTime::parse($endDayLocal->toDateString().' '.substr($endsAtLocal, 0, 5), $ruleTimezone, 'end');
                } catch (ValidationException) {
                    // A clock-change gap/fold cannot be silently offered as a bookable shift.
                    continue;
                }
                $slot = $windowStart->copy()->max($rangeStartsAt->copy()->timezone($timezone));

                while ($slot->copy()->addMinutes($durationMinutes)->lte($windowEnd) && $slot->copy()->addMinutes($durationMinutes)->lte($rangeEndsAt->copy()->timezone($timezone)) && $slots->count() < $limit) {
                    $slotEnd = $slot->copy()->addMinutes($durationMinutes);

                    $blocked = $overrides->get($slot->copy()->timezone($ruleTimezone)->toDateString(), collect())
                        ->filter(fn ($override) => in_array($override->availability_type, ['unavailable', 'out_of_office'], true))
                        ->contains(function ($override) use ($slot, $slotEnd, $ruleTimezone) {
                            if (! $override->starts_at_local || ! $override->ends_at_local) {
                                return true;
                            }
                            $start = Carbon::parse($override->date->toDateString().' '.$override->starts_at_local, $ruleTimezone);
                            $end = Carbon::parse($override->date->toDateString().' '.$override->ends_at_local, $ruleTimezone);
                            if ($end->lte($start)) {
                                $end->addDay();
                            }

                            return $start->lt($slotEnd) && $end->gt($slot);
                        });
                    if (! $blocked && $this->availability->isFree([$calendar], $slot->copy(), $slotEnd->copy())) {
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

        return $slots->unique(fn ($slot) => $slot['starts_at']->timestamp)->values();
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
