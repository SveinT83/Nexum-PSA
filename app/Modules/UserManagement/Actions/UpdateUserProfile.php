<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UpdateUserProfile
{
    /*
    |--------------------------------------------------------------------------
    | Canonical user profile update
    |--------------------------------------------------------------------------
    |
    | Both the self-service profile and Admin employee profile use this action
    | so contact details, working hours, and avatar behavior stay identical.
    |
    */
    public function handle(User $user, array $data, ?UploadedFile $avatar = null): UserProfile
    {
        $profile = UserProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'work_phone' => $user->phone_work,
                'private_phone' => $user->phone_private,
                'timezone' => config('app.timezone', 'UTC'),
                'working_hours' => UserProfileData::defaultWorkingHours(),
            ]
        );

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone_work' => $data['work_phone'] ?? $data['phone_work'] ?? null,
            'phone_private' => $data['private_phone'] ?? $data['phone_private'] ?? null,
        ])->save();

        $payload = [
            'work_phone' => $data['work_phone'] ?? $data['phone_work'] ?? null,
            'private_phone' => $data['private_phone'] ?? $data['phone_private'] ?? null,
            'timezone' => $data['timezone'],
            'working_hours' => UserProfileData::normalizeWorkingHours($data['working_hours'] ?? []),
            'availability_notes' => $data['availability_notes'] ?? null,
            'profile_notes' => $data['profile_notes'] ?? null,
        ];

        if (! empty($data['remove_avatar']) && filled($profile->avatar_path)) {
            Storage::disk('public')->delete($profile->avatar_path);
            $payload['avatar_path'] = null;
        }

        if ($avatar) {
            if (filled($profile->avatar_path)) {
                Storage::disk('public')->delete($profile->avatar_path);
            }

            $payload['avatar_path'] = $avatar->store('user-avatars', 'public');
        }

        $profile->forceFill($payload)->save();

        return $profile;
    }
}
