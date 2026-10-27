<?php

namespace App\Modules\UserManagement\Actions;

use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/** Shared explicit deletion; global authorization generation invalidates dependent reads. */
class DeleteUserPermission
{
    public function handle(Permission $permission): void
    {
        DB::transaction(function () use ($permission): void {
            $locked = Permission::query()->lockForUpdate()->findOrFail($permission->id);
            app(EmailLiveAuthorityCoordinator::class)->prepareRoleContentMutation(0);
            $locked->delete();
        });
    }
}
