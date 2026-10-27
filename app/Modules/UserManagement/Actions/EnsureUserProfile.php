<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Support\UserProfileData;

/** The browser and own/detail API expose the same canonical default profile. */
class EnsureUserProfile
{
    public function handle(User $user): UserProfile
    {
        $profile = UserProfile::firstOrCreate(['user_id' => $user->id], [
            'work_phone' => $user->phone_work, 'private_phone' => $user->phone_private,
            'timezone' => config('app.timezone', 'UTC'), 'working_hours' => UserProfileData::defaultWorkingHours(),
        ]);
        if (empty($profile->working_hours)) {
            $profile->forceFill(['working_hours' => UserProfileData::defaultWorkingHours()])->save();
        }

        return $profile;
    }
}
