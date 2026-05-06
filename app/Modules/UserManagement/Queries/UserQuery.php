<?php

namespace App\Modules\UserManagement\Queries;

use App\Models\Core\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query object for system users.
 *
 * The public URL segment is /admin/user_management, but the real database table
 * is controlled by App\Models\Core\User. Do not hard-code user_management as a
 * table name in this module.
 */
class UserQuery
{
    public function paginateForAdminIndex(int $perPage = 20): LengthAwarePaginator
    {
        return User::query()
            ->with('roles')
            ->orderBy('name')
            ->paginate($perPage);
    }
}
