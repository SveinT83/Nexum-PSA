<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Models\UserProfile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates an application user from the admin form.
 *
 * The current form does not ask for a password. This action creates a random
 * initial password and marks the user as pending invite, which matches the
 * User model's default status semantics.
 *
 * When the user is created with PENDING_INVITE status (the default), the
 * invitation email is sent automatically.
 */
class StoreUser
{
    public function handle(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make(Str::random(32)),
            'status' => $data['status'] ?? User::STATUS_PENDING,
        ]);

        if (! empty($data['role'])) {
            $role = Role::findOrFail($data['role']);
            $user->assignRole($role);
        }

        UserProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'timezone' => config('app.timezone', 'UTC'),
                'working_hours' => $this->defaultWorkingHours(),
            ]
        );

        // Auto-send invite for pending users
        if ($user->isPending()) {
            app(SendUserInvite::class)->handle($user);
        }

        return $user;
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
