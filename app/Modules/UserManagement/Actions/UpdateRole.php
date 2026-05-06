<?php

namespace App\Modules\UserManagement\Actions;

use Spatie\Permission\Models\Role;

class UpdateRole
{
    public function handle(Role $role, array $data): Role
    {
        $role->update(['name' => $data['name']]);

        return $role;
    }
}
