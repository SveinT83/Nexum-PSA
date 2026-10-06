<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserPreference;

class UpdateUserPreferences
{
    public function handle(User $user, array $data): UserPreference
    {
        $existing = UserPreference::query()->where('user_id', $user->id)->first();

        $preferences = UserPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'timezone' => $data['timezone'],
                'default_calendar_view' => $data['default_calendar_view'],
                'workday_start' => $data['workday_start'],
                'workday_end' => $data['workday_end'],
                'settings' => array_merge($existing?->settings ?? [], [
                    'theme' => $data['theme'] ?? data_get($existing?->settings, 'theme', 'company'),
                ]),
            ]
        );

        // These are display defaults only. Normal weekly hours belong to UserProfile;
        // saving a theme or calendar view must never rewrite availability rules.

        return $preferences;
    }
}
