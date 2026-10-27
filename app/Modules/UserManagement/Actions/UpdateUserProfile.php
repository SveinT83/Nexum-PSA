<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        $created = [];
        try {
            return DB::transaction(function () use ($user, $data, $avatar, &$created) {
                $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                return $this->persist($user, $data, $avatar, $created);
            });
        } catch (\Throwable $error) {
            foreach ($created as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $error;
        }
    }

    private function persist(User $user, array $data, ?UploadedFile $avatar, array &$created): UserProfile
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

        $oldAvatar = $profile->avatar_path;
        if (! empty($data['remove_avatar'])) {
            $payload['avatar_path'] = null;
        }
        if ($avatar) {
            $payload['avatar_path'] = $avatar->store('user-avatars', 'public');
            $created[] = $payload['avatar_path'];
            // The API's enclosing command transaction also owns rollback compensation.
            if (request()->attributes->get('um_command_transaction')) {
                request()->attributes->set('um_created_avatars', array_merge(
                    request()->attributes->get('um_created_avatars', []), [$payload['avatar_path']]));
            }
        }
        if (array_key_exists('avatar_path', $payload) && $oldAvatar && $oldAvatar !== $payload['avatar_path']) {
            DB::afterCommit(fn () => Storage::disk('public')->delete($oldAvatar));
        }

        $profile->forceFill($payload)->save();

        // Existing self/admin profile updates retain their authority and use the same
        // projection as the dedicated schedule action, without replacing security guards.
        if (config('workday.enabled')) {
            app(UserWorkPlan::class)->project($user);
        }

        return $profile;
    }
}
