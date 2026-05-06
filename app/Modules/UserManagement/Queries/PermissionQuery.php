<?php

namespace App\Modules\UserManagement\Queries;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Permission;

class PermissionQuery
{
    public function allOrdered(): Collection
    {
        return Permission::orderBy('name')->get();
    }
}
