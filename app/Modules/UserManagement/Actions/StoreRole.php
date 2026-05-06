<?php

namespace App\Modules\UserManagement\Actions;

use Spatie\Permission\Models\Role;

class StoreRole
{
    public function handle(array $data): Role
    {
        return Role::create([
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
        ]);
    }
}
