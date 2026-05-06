<?php

namespace App\Modules\UserManagement\Actions;

use Spatie\Permission\Models\Permission;

class StorePermission
{
    public function handle(array $data): Permission
    {
        return Permission::create([
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'web',
        ]);
    }
}
