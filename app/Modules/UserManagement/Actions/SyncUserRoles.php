<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncUserRoles
{
    public function __construct(private readonly EmailLiveAuthorityCoordinator $liveAuthority) {}

    public function handle(User $user, Collection $roles): User
    {
        return DB::transaction(function () use ($roles, $user): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $authority = $this->liveAuthority->prepareUserContentMutation((int) $locked->id);
            $locked->syncRoles($roles);
            $this->liveAuthority->syncUserContentPaths($locked, $authority['content_generation']);

            return $locked->refresh();
        }, 3);
    }
}
