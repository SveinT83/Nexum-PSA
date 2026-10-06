<?php

namespace App\Modules\Integration\Support;

use App\Models\Core\User;

final class TripletexAccess
{
    public function authorize(?User $user): void
    {
        abort_unless($user && $user->isActive() && ! $user->isSystemActor()
            && $user->checkPermissionTo('integration.tripletex_manage', 'web'), 403);
    }
}
