<?php

namespace App\Modules\UserManagement\Actions;

use Spatie\Permission\Models\Permission;

class UpdatePermission
{
    public function handle(Permission $permission, array $data): Permission
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($permission, $data): Permission {
            $locked = Permission::query()->lockForUpdate()->findOrFail($permission->id);
            if ($locked->name !== $data['name']) {
                app(\App\Modules\Email\Services\EmailLiveAuthorityCoordinator::class)->prepareRoleContentMutation(0);
                $locked->update(['name' => $data['name']]);
            }

            return $locked;
        });
    }
}
