<?php

namespace App\Modules\Booking\Actions;

use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\FindAvailableSlots;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FindBookingSlots
{
    public function __construct(
        private readonly EnsureCalendarDefaults $calendarDefaults,
        private readonly FindAvailableSlots $calendarSlots,
    ) {}

    /**
     * Return public-safe slots for one bookable service setting.
     */
    public function forSetting(BookingServiceSetting $setting, ?Carbon $from = null, ?Carbon $to = null, int $limit = 40): Collection
    {
        if (! $setting->isBookable() || ! $setting->assignedUser) {
            return collect();
        }

        $calendar = $this->calendarDefaults->ensurePersonalCalendar($setting->assignedUser);
        $timezone = $calendar->timezone ?: 'Europe/Oslo';
        [$rangeStartsAt, $rangeEndsAt] = $this->boundedRange($setting, $from, $to, $timezone);

        if ($rangeStartsAt->gte($rangeEndsAt)) {
            return collect();
        }

        $step = max(15, (int) $setting->slot_step_minutes);

        return $this->calendarSlots
            ->handle($setting->assignedUser, $rangeStartsAt, $rangeEndsAt, (int) $setting->duration_minutes, $limit * 4)
            ->filter(function (array $slot) use ($step): bool {
                $startsAt = $slot['starts_at'];
                $minutesSinceMidnight = ((int) $startsAt->format('H') * 60) + (int) $startsAt->format('i');

                return $minutesSinceMidnight % $step === 0;
            })
            ->take($limit)
            ->values();
    }

    public function isSlotAvailable(BookingServiceSetting $setting, Carbon $startsAt, ?Carbon $endsAt = null): bool
    {
        $timezone = $setting->assignedUser
            ? ($this->calendarDefaults->ensurePersonalCalendar($setting->assignedUser)->timezone ?: 'Europe/Oslo')
            : ($setting->timezone ?? 'Europe/Oslo');

        $startsAt = $startsAt->copy()->timezone($timezone);
        $endsAt = ($endsAt ?: $startsAt->copy()->addMinutes((int) $setting->duration_minutes))->copy()->timezone($timezone);

        return $this->forSetting(
            $setting,
            $startsAt->copy()->startOfDay(),
            $startsAt->copy()->endOfDay(),
            200,
        )->contains(function (array $slot) use ($startsAt, $endsAt): bool {
            return $slot['starts_at']->getTimestamp() === $startsAt->getTimestamp()
                && $slot['ends_at']->getTimestamp() === $endsAt->getTimestamp();
        });
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function boundedRange(BookingServiceSetting $setting, ?Carbon $from, ?Carbon $to, string $timezone): array
    {
        $now = now($timezone);
        $minStartsAt = $now->copy()->addHours((int) $setting->min_notice_hours);
        $horizonEndsAt = $now->copy()->addDays((int) $setting->horizon_days)->endOfDay();

        $rangeStartsAt = ($from ?: $now)->copy()->timezone($timezone);
        if ($rangeStartsAt->lt($minStartsAt)) {
            $rangeStartsAt = $minStartsAt;
        }

        $rangeEndsAt = ($to ?: $rangeStartsAt->copy()->addDays(14)->endOfDay())->copy()->timezone($timezone);
        if ($rangeEndsAt->gt($horizonEndsAt)) {
            $rangeEndsAt = $horizonEndsAt;
        }

        return [$rangeStartsAt, $rangeEndsAt];
    }
}
