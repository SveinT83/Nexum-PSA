<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotently creates canonical User Management profiles for existing users.
 *
 * This is safe to run during production upgrades. Existing profile rows are
 * only filled where data is missing unless force mode is explicitly enabled.
 */
class BackfillUserProfiles
{
    public function handle(bool $force = false): array
    {
        $summary = [
            'users_seen' => 0,
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'ticket_profiles_used' => 0,
        ];

        if (! Schema::hasTable('user_profiles')) {
            return $summary;
        }

        User::query()
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$summary, $force) {
                foreach ($users as $user) {
                    $summary['users_seen']++;

                    $ticketProfile = Schema::hasTable('ticket_technician_profiles')
                        ? DB::table('ticket_technician_profiles')->where('user_id', $user->id)->first()
                        : null;

                    $profile = UserProfile::query()->firstOrNew(['user_id' => $user->id]);
                    $wasRecentlyNew = ! $profile->exists;

                    if ($ticketProfile) {
                        $summary['ticket_profiles_used']++;
                    }

                    $values = $this->profileValues($user, $ticketProfile);

                    foreach ($values as $key => $value) {
                        if ($force || $profile->{$key} === null || $profile->{$key} === []) {
                            $profile->{$key} = $value;
                        }
                    }

                    if ($profile->isDirty()) {
                        $profile->save();

                        if ($wasRecentlyNew) {
                            $summary['profiles_created']++;
                        } else {
                            $summary['profiles_updated']++;
                        }
                    }
                }
            });

        return $summary;
    }

    private function profileValues(User $user, ?object $ticketProfile): array
    {
        return [
            'work_phone' => $user->phone_work,
            'private_phone' => $user->phone_private,
            'timezone' => $ticketProfile?->timezone ?? config('app.timezone', 'UTC'),
            'working_hours' => $ticketProfile?->working_hours ?? $this->defaultWorkingHours(),
            'profile_notes' => $ticketProfile?->notes,
            'migrated_from_ticket_technician_profile_id' => $ticketProfile?->id,
            'migrated_at' => $ticketProfile ? now() : null,
        ];
    }

    private function defaultWorkingHours(): array
    {
        return collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn (string $day) => [$day => [
                'enabled' => in_array($day, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], true),
                'start' => '08:00',
                'end' => '16:00',
            ]])
            ->all();
    }
}
