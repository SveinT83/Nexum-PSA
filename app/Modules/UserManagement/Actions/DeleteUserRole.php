<?php

namespace App\Modules\UserManagement\Actions;

use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/** Permission removal invalidates live Mail authorization in the owning transaction. */
class DeleteUserRole
{
    public function handle(Role $role): void
    {
        DB::transaction(function () use ($role): void {
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            app(EmailLiveAuthorityCoordinator::class)->prepareRoleContentMutation((int) $locked->id);
            $locked->delete();
        });
    }
}
