<?php

namespace App\Modules\Booking\Actions;

use App\Models\Core\User;
use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\FindAvailableSlots;
use App\Modules\Calendar\Models\CalendarSetting;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FindBookingSlots
{
    private const WEEKDAYS = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    public function __construct(
        private readonly EnsureCalendarDefaults $calendarDefaults,
        private readonly FindAvailableSlots $calendarSlots,
    ) {}

    /**
     * Return public-safe slots without leaking automatic-routing identities.
     */
    public function forSetting(
        BookingServiceSetting $setting,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $limit = 40,
        ?User $requestedUser = null,
    ): Collection {
        if (! $setting->isBookable()) {
            return collect();
        }

        $users = $this->candidateUsers($setting, $requestedUser);
        if ($users->isEmpty()) {
            return collect();
        }

        $publicTimezone = $this->publicTimezone();
        [$rangeStartsAt, $rangeEndsAt] = $this->boundedRange(
            $setting,
            $from,
            $to,
            $publicTimezone,
        );

        if ($rangeStartsAt->gte($rangeEndsAt)) {
            return collect();
        }

        $step = max(15, (int) $setting->slot_step_minutes);
        $slots = collect();

        foreach ($users as $user) {
            $this->calendarDefaults->ensurePersonalCalendar($user);

            $candidateSlots = $this->calendarSlots->handle(
                $user,
                $rangeStartsAt,
                $rangeEndsAt,
                (int) $setting->duration_minutes,
                max($limit * 4, 80),
                $this->availabilityWindows($setting, $user),
            );

            foreach ($candidateSlots as $slot) {
                $startsAt = $slot['starts_at']->copy()->timezone($publicTimezone);
                $endsAt = $slot['ends_at']->copy()->timezone($publicTimezone);

                if (! $this->isAlignedWithStep($startsAt, $step)) {
                    continue;
                }

                $slots->push([
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'timezone' => $publicTimezone,
                ]);
            }
        }

        return $slots
            ->unique(fn (array $slot): string => $slot['starts_at']->getTimestamp().':'.$slot['ends_at']->getTimestamp())
            ->sortBy(fn (array $slot): int => $slot['starts_at']->getTimestamp())
            ->take($limit)
            ->values();
    }

    /**
     * Return only active technicians intentionally exposed in customer-choice mode.
     */
    public function eligibleUsersForPublic(BookingServiceSetting $setting): Collection
    {
        if (! $setting->allowsCustomerTechnicianChoice()) {
            return collect();
        }

        return $this->eligibleUsers($setting);
    }

    public function publicTimezone(): string
    {
        return $this->calendarSetting('default_timezone', 'Europe/Oslo');
    }

    public function resolveUserForRequest(
        BookingServiceSetting $setting,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $requestedUserId = null,
    ): ?User {
        if (! $this->insidePublicBookingRange($setting, $startsAt, $endsAt)) {
            return null;
        }

        $requestedUser = $requestedUserId ? User::query()->find($requestedUserId) : null;

        foreach ($this->candidateUsers($setting, $requestedUser) as $user) {
            if ($this->isUserAvailableWithinPolicy($setting, $user, $startsAt, $endsAt)) {
                return $user;
            }
        }

        return null;
    }

    public function resolveUserForConfirmation(
        BookingServiceSetting $setting,
        ?User $preferredUser,
        Carbon $startsAt,
        Carbon $endsAt,
    ): ?User {
        if (! $setting->usesAutomaticAssignment()) {
            return $preferredUser && $preferredUser->isActive()
                && $this->isUserAvailableWithinPolicy($setting, $preferredUser, $startsAt, $endsAt)
                    ? $preferredUser
                    : null;
        }

        $eligibleUsers = $this->eligibleUsers($setting);
        if ($preferredUser && $eligibleUsers->contains('id', $preferredUser->id)) {
            $eligibleUsers = collect([$preferredUser])
                ->merge($eligibleUsers->reject(fn (User $user): bool => $user->id === $preferredUser->id));
        }

        foreach ($eligibleUsers as $user) {
            if ($this->isUserAvailableWithinPolicy($setting, $user, $startsAt, $endsAt)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, User>
     */
    private function candidateUsers(BookingServiceSetting $setting, ?User $requestedUser = null): Collection
    {
        if ($setting->usesFixedTechnician()) {
            $user = $setting->assignedUser;

            return $user && $user->isActive() ? collect([$user]) : collect();
        }

        $eligibleUsers = $this->eligibleUsers($setting);

        if (! $setting->allowsCustomerTechnicianChoice()) {
            return $eligibleUsers;
        }

        if (! $requestedUser) {
            return collect();
        }

        $eligibleUser = $eligibleUsers->firstWhere('id', $requestedUser->id);

        return $eligibleUser ? collect([$eligibleUser]) : collect();
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleUsers(BookingServiceSetting $setting): Collection
    {
        if ($setting->relationLoaded('eligibleUsers')) {
            return $setting->eligibleUsers
                ->filter(fn (User $user): bool => $user->isActive())
                ->sortBy('name')
                ->values();
        }

        return $setting->eligibleUsers()
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }

    private function isUserAvailableWithinPolicy(
        BookingServiceSetting $setting,
        User $user,
        Carbon $startsAt,
        Carbon $endsAt,
    ): bool {
        if ($endsAt->getTimestamp() !== $startsAt->copy()->addMinutes((int) $setting->duration_minutes)->getTimestamp()) {
            return false;
        }

        $startsAt = $startsAt->copy();
        $endsAt = $endsAt->copy();
        if (! $this->isAlignedWithStep($startsAt->copy()->timezone($this->publicTimezone()), max(15, (int) $setting->slot_step_minutes))) {
            return false;
        }

        $this->calendarDefaults->ensurePersonalCalendar($user);

        return $this->calendarSlots
            ->handle(
                $user,
                $startsAt,
                $endsAt,
                (int) $setting->duration_minutes,
                5,
                $this->availabilityWindows($setting, $user),
            )
            ->contains(function (array $slot) use ($startsAt, $endsAt): bool {
                return $slot['starts_at']->getTimestamp() === $startsAt->getTimestamp()
                    && $slot['ends_at']->getTimestamp() === $endsAt->getTimestamp();
            });
    }

    /**
     * @return array{timezone: string, weekdays: array<int, array<int, array{start: string, end: string}>>}
     */
    private function availabilityWindows(BookingServiceSetting $setting, User $user): array
    {
        if ($setting->working_hours_source === BookingServiceSetting::HOURS_TECHNICIAN) {
            $user->loadMissing('profile');
            $calendar = $this->calendarDefaults->ensurePersonalCalendar($user);
            $timezone = $user->profile?->timezone ?: ($calendar->timezone ?: $this->publicTimezone());
            $workingHours = $user->profile?->working_hours ?: UserProfileData::defaultWorkingHours();
        } else {
            $timezone = $this->publicTimezone();
            $workingHours = UserProfileData::defaultWorkingHours();
            $companyStart = $this->calendarSetting('default_workday_start', '08:00');
            $companyEnd = $this->calendarSetting('default_workday_end', '16:00');

            foreach ($workingHours as $day => $hours) {
                if ($hours['enabled'] ?? false) {
                    $workingHours[$day]['start'] = $companyStart;
                    $workingHours[$day]['end'] = $companyEnd;
                }
            }
        }

        $openingStart = $this->normalizedTime($setting->opening_window_start);
        $openingEnd = $this->normalizedTime($setting->opening_window_end);
        $weekdays = [];

        foreach (self::WEEKDAYS as $day => $weekday) {
            $hours = $workingHours[$day] ?? null;
            if (! is_array($hours) || ! ($hours['enabled'] ?? false)) {
                continue;
            }

            $start = $this->normalizedTime($hours['start'] ?? null) ?: '08:00';
            $end = $this->normalizedTime($hours['end'] ?? null) ?: '16:00';

            if ($openingStart && $openingEnd) {
                $start = max($start, $openingStart);
                $end = min($end, $openingEnd);
            }

            if ($start < $end) {
                $weekdays[$weekday] = [['start' => $start, 'end' => $end]];
            }
        }

        return ['timezone' => $timezone, 'weekdays' => $weekdays];
    }

    private function insidePublicBookingRange(
        BookingServiceSetting $setting,
        Carbon $startsAt,
        Carbon $endsAt,
    ): bool {
        $now = now($this->publicTimezone());
        $minimum = $now->copy()->addHours((int) $setting->min_notice_hours);
        $horizon = $now->copy()->addDays((int) $setting->horizon_days)->endOfDay();

        return $startsAt->copy()->timezone($this->publicTimezone())->gte($minimum)
            && $endsAt->copy()->timezone($this->publicTimezone())->lte($horizon);
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

    private function calendarSetting(string $name, string $fallback): string
    {
        return CalendarSetting::query()
            ->where('scope_type', 'system')
            ->whereNull('scope_id')
            ->where('name', $name)
            ->value('value') ?: $fallback;
    }

    private function normalizedTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 5);
    }

    private function isAlignedWithStep(Carbon $startsAt, int $step): bool
    {
        $minutesSinceMidnight = ((int) $startsAt->format('H') * 60) + (int) $startsAt->format('i');

        return $minutesSinceMidnight % $step === 0;
    }
}
