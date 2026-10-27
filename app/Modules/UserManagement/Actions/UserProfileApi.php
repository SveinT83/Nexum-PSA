<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserPreference;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Resources\Api\V1\UserResource;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Own profile/preferences use the same canonical actions as the employee forms. */
class UserProfileApi
{
    public function profile(User $user): array
    {
        app(EnsureUserProfile::class)->handle($user);

        return UserResource::make($user->load(['profile', 'preferences', 'roles', 'permissions']))->resolve();
    }

    public function update(Request $request): array
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique($user->getTable(), 'email')->ignore($user->id)],
            'work_phone' => ['nullable', 'string', 'max:50'], 'private_phone' => ['nullable', 'string', 'max:50'],
            'phone_work' => ['nullable', 'string', 'max:50'], 'phone_private' => ['nullable', 'string', 'max:50'],
            'timezone' => ['sometimes', 'required', 'timezone'], 'working_hours' => ['nullable', 'array'],
            'working_hours.*.enabled' => ['nullable', 'boolean'], 'working_hours.*.start' => ['nullable', 'date_format:H:i'],
            'working_hours.*.end' => ['nullable', 'date_format:H:i'], 'availability_notes' => ['nullable', 'string', 'max:5000'],
            'profile_notes' => ['nullable', 'string', 'max:5000'], 'avatar' => ['nullable', 'image', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);
        $profile = UserProfile::where('user_id', $user->id)->first();
        $payload = array_merge(['name' => $user->name, 'email' => $user->email,
            'work_phone' => $user->phone_work, 'private_phone' => $user->phone_private,
            'timezone' => $profile?->timezone ?: config('app.timezone', 'UTC'),
            'working_hours' => $profile?->working_hours ?: UserProfileData::defaultWorkingHours(),
            'availability_notes' => $profile?->availability_notes, 'profile_notes' => $profile?->profile_notes], $data);
        foreach (['work_phone' => 'phone_work', 'private_phone' => 'phone_private'] as $canonical => $alias) {
            if (array_key_exists($alias, $data) && ! array_key_exists($canonical, $data)) {
                $payload[$canonical] = $data[$alias];
            }
        }
        app(UpdateUserProfile::class)->handle($user, $payload, $request->file('avatar'));

        return $this->profile($user->refresh());
    }

    public function preferences(User $user): array
    {
        $row = UserPreference::where('user_id', $user->id)->first();

        return ['timezone' => $row?->timezone ?: config('app.timezone', 'Europe/Oslo'),
            'default_calendar_view' => $row?->default_calendar_view ?: 'week',
            'workday_start' => $row?->workday_start ?: '08:00', 'workday_end' => $row?->workday_end ?: '16:00',
            'theme' => data_get($row?->settings, 'theme', 'company')];
    }

    public function updatePreferences(Request $request): array
    {
        $data = $request->validate(['timezone' => ['sometimes', 'required', 'timezone'],
            'default_calendar_view' => ['sometimes', Rule::in(['day', 'week', 'month', 'list'])],
            'workday_start' => ['sometimes', 'date_format:H:i'], 'workday_end' => ['sometimes', 'date_format:H:i'],
            'theme' => ['sometimes', Rule::in(['company', 'system', 'light', 'dark'])]]);
        $input = array_merge($this->preferences($request->user()), $data);
        abort_unless($input['workday_end'] > $input['workday_start'], 422, 'Workday end must follow start.');
        app(UpdateUserPreferences::class)->handle($request->user(), $input);

        return $this->preferences($request->user());
    }
}
