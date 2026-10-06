<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UserWorkPlan
{
    public const SOURCE = 'user_profile_work_plan';

    /** A read is also the conflict preview. It never creates a profile or calendar. */
    public function read(User $user): array
    {
        $profile = $user->profile()->first();
        $preferences = $user->preferences()->first();
        $calendar = $this->calendar($user);
        $rules = $calendar ? $calendar->availabilityRules()->orderBy('id')->get() : collect();
        $hours = $profile?->working_hours ?: UserProfileData::defaultWorkingHours();
        if (! $profile && $preferences) {
            foreach ($hours as &$day) {
                $day['start'] = substr($preferences->workday_start, 0, 5);
                $day['end'] = substr($preferences->workday_end, 0, 5);
            }
            unset($day);
        }
        $timezone = $profile?->timezone ?: ($preferences?->timezone ?: config('app.timezone'));
        $conflicts = $rules->filter(fn ($rule) => ! in_array(data_get($rule->metadata, 'source'),
            [self::SOURCE, 'calendar_default'], true))
            ->map(fn ($rule) => [
                'id' => $rule->id, 'weekday' => $rule->weekday, 'timezone' => $rule->timezone,
                'start' => $rule->starts_at_local, 'end' => $rule->ends_at_local,
                'effective_from' => $rule->effective_from?->toDateString(),
                'effective_until' => $rule->effective_until?->toDateString(),
            ])->values()->all();

        return [
            'timezone' => $timezone,
            'working_hours' => $hours,
            'origin' => $profile ? 'profile' : ($preferences ? 'legacy_preferences' : 'default'),
            'revision' => hash('sha256', json_encode([
                $profile?->getAttributes(), $preferences?->only(['timezone', 'workday_start', 'workday_end']),
                $calendar?->only(['timezone', 'metadata']), $rules->toArray(),
            ])),
            'calendar_timezone' => $calendar?->timezone,
            'calendar_conflicts' => $conflicts,
        ];
    }

    /** Save only schedule fields; do not enter the account-security update workflow. */
    public function update(User $user, array $data): array
    {
        $rules = [
            'revision' => ['required', 'string', 'size:64'],
            'timezone' => ['required', 'timezone'],
            'working_hours' => ['required', 'array:'.implode(',', array_keys(UserProfileData::defaultWorkingHours()))],
            'accept_calendar_conflicts' => ['sometimes', 'boolean'],
        ];
        foreach (UserProfileData::defaultWorkingHours() as $day => $_) {
            $rules["working_hours.$day"] = ['required', 'array:enabled,start,end'];
            $rules["working_hours.$day.enabled"] = ['required', 'boolean'];
            $rules["working_hours.$day.start"] = ['required', 'date_format:H:i'];
            $rules["working_hours.$day.end"] = ['required', 'date_format:H:i', "different:working_hours.$day.start"];
        }
        $data = Validator::make($data, $rules)->validate();

        return DB::transaction(function () use ($user, $data) {
            // The stable user row serializes first creation as well as subsequent edits.
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $before = $this->read($user);
            abort_unless(hash_equals($before['revision'], $data['revision']), 409,
                'The work plan changed. Reload and review it before saving.');
            if ($before['calendar_conflicts'] && ! ($data['accept_calendar_conflicts'] ?? false)) {
                throw ValidationException::withMessages([
                    'accept_calendar_conflicts' => 'Review the existing Calendar rules. They will be preserved as exceptions.',
                ]);
            }

            UserProfile::query()->updateOrCreate(['user_id' => $user->id], [
                'timezone' => $data['timezone'],
                'working_hours' => UserProfileData::normalizeWorkingHours($data['working_hours']),
            ]);
            $this->project($user);

            return $this->read($user);
        });
    }

    /** Calendar keeps dated projections. Unowned rules are never deleted or reclassified. */
    public function project(User $user): void
    {
        $profile = $user->profile()->firstOrFail();
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($user);
        $today = now($profile->timezone)->startOfDay();
        $owned = $calendar->availabilityRules()->get()->filter(fn ($rule) => in_array(data_get($rule->metadata, 'source'), [self::SOURCE, 'calendar_default'], true));
        foreach ($owned as $rule) {
            if ($rule->effective_until && $rule->effective_until->lt($today->toDateString())) {
                continue;
            }
            if ($rule->effective_from && $rule->effective_from->gte($today->toDateString())) {
                $rule->delete();
            } else {
                $rule->update(['effective_until' => $today->copy()->subDay()->toDateString()]);
            }
        }
        foreach (array_keys(UserProfileData::defaultWorkingHours()) as $index => $name) {
            $day = $profile->working_hours[$name];
            CalendarAvailabilityRule::create([
                'calendar_id' => $calendar->id, 'user_id' => $user->id,
                'timezone' => $profile->timezone, 'weekday' => $index + 1,
                'starts_at_local' => $day['start'], 'ends_at_local' => $day['end'],
                'effective_from' => $today->toDateString(),
                'metadata' => ['source' => self::SOURCE, 'enabled' => (bool) $day['enabled']],
            ]);
        }
        $calendar->update([
            'timezone' => $profile->timezone,
            'metadata' => array_merge($calendar->metadata ?? [], ['work_plan_managed' => true]),
        ]);
    }

    public function calendar(User $user): ?Calendar
    {
        return Calendar::query()->where('type', 'personal')
            ->where('owner_type', $user::class)->where('owner_id', $user->id)->first();
    }
}
