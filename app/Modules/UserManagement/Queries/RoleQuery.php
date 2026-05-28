<?php

namespace App\Modules\UserManagement\Queries;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

class RoleQuery
{
    public function allWithPermissions(): Collection
    {
        return Role::query()
            ->withCount(['permissions', 'users'])
            ->orderBy('name')
            ->get();
    }
}
