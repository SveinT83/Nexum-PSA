<?php

namespace App\Modules\UserManagement\Actions;

use Spatie\Permission\Models\Permission;

class UpdatePermission
{
    public function handle(Permission $permission, array $data): Permission
    {
        $permission->update(['name' => $data['name']]);

        return $permission;
    }
}
