<?php

namespace App\Modules\UserManagement\Actions;

use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use App\Modules\UserManagement\Support\UserManagementApiAccess;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Explicit desired membership is shared by web and API and safe to repeat. */
class SetRolePermission
{
    public function handle(Role $role, string $permissionName, bool $enabled): Role
    {
        $actor = UserManagementApiAccess::actor(request());
        UserManagementApiAccess::authorize($actor, ['user.manage_roles']);

        return DB::transaction(function () use ($role, $permissionName, $enabled): Role {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            $permission = Permission::where('name', $permissionName)->where('guard_name', $role->guard_name)->firstOrFail();
            if ($role->hasPermissionTo($permission) !== $enabled) {
                if ($permissionName === 'email.inbox_view') {
                    app(EmailLiveAuthorityCoordinator::class)->prepareRoleContentMutation((int) $role->id);
                }
                $enabled ? $role->givePermissionTo($permission) : $role->revokePermissionTo($permission);
            }

            return $role->fresh('permissions');
        }, 3);
    }
}
