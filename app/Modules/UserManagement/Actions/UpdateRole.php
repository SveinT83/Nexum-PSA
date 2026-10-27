<?php

namespace App\Modules\UserManagement\Actions;

use Spatie\Permission\Models\Role;

class UpdateRole
{
    public function handle(Role $role, array $data): Role
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($role, $data): Role {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            if ($locked->name !== $data['name']) {
                app(\App\Modules\Email\Services\EmailLiveAuthorityCoordinator::class)->prepareRoleContentMutation($locked->id);
                $locked->update(['name' => $data['name']]);
            }

            return $locked;
        });
    }
}
