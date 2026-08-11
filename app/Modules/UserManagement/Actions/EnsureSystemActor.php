<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Ensure a protected, non-login identity for unattended domain actions.
 */
class EnsureSystemActor
{
    /** @param list<string> $permissions */
    public function handle(
        string $key,
        string $name,
        string $email,
        array $permissions,
    ): User {
        return DB::transaction(function () use ($key, $name, $email, $permissions): User {
            $actor = User::query()
                ->where('system_actor_key', $key)
                ->lockForUpdate()
                ->first();

            if (! $actor) {
                $actor = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(96)),
                    'status' => User::STATUS_DISABLED,
                    'is_system_actor' => true,
                    'system_actor_key' => $key,
                ]);
            } else {
                $actor->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'status' => User::STATUS_DISABLED,
                    'is_system_actor' => true,
                ])->save();
            }

            $permissionModels = collect($permissions)
                ->unique()
                ->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'));
            $actor->syncRoles([]);
            $actor->syncPermissions($permissionModels);

            return $actor->refresh()->load('permissions');
        });
    }
}
