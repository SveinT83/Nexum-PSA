<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FindAvailableSlots
{
    public function __construct(private CheckAvailability $availability)
    {
    }

    /**
     * Find bookable slots inside a user's working rules.
     */
    public function handle(User $user, Carbon $rangeStartsAt, Carbon $rangeEndsAt, int $durationMinutes, int $limit = 20): Collection
    {
        $calendar = Calendar::query()
            ->where('type', 'personal')
            ->where('owner_type', $user::class)
            ->where('owner_id', $user->id)
            ->where('is_active', true)
            ->first();

        if (! $calendar) {
            return collect();
        }

        $timezone = $calendar->timezone ?: 'Europe/Oslo';
        $rules = CalendarAvailabilityRule::query()
            ->where(function ($query) use ($calendar, $user) {
                $query->where('calendar_id', $calendar->id)
                    ->orWhere('user_id', $user->id);
            })
            ->get()
            ->groupBy('weekday');

        $slots = collect();
        $cursorDay = $rangeStartsAt->copy()->timezone($timezone)->startOfDay();
        $endDay = $rangeEndsAt->copy()->timezone($timezone)->startOfDay();

        while ($cursorDay->lte($endDay) && $slots->count() < $limit) {
            $weekday = (int) $cursorDay->dayOfWeekIso;

            foreach ($rules->get($weekday, collect()) as $rule) {
                $windowStart = Carbon::parse($cursorDay->toDateString().' '.$rule->starts_at_local, $timezone);
                $windowEnd = Carbon::parse($cursorDay->toDateString().' '.$rule->ends_at_local, $timezone);
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
}
