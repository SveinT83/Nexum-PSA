<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates an application user from the admin form.
 *
 * The current form does not ask for a password. This action creates a random
 * initial password and marks the user as pending invite, which matches the
 * User model's default status semantics.
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

        return $user;
    }
}
