<?php

namespace App\Modules\UserManagement\Queries;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

class RoleQuery
{
    public function allWithPermissions(): Collection
    {
        return Role::with('permissions')->orderBy('name')->get();
    }
}
