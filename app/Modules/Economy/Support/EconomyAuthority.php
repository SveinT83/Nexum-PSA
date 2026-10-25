<?php

namespace App\Modules\Economy\Support;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Http\Request;

/** Token authority never substitutes for the current user's browser domain permission. */
class EconomyAuthority
{
    public static function check(Request $request): void
    {
        $old = $request->user();
        $actor = $old ? User::query()->find($old->id) : null;
        abort_unless($actor && $actor->isActive(), 403, 'An active actor is required.');
        $actor->withAccessToken($old->currentAccessToken());
        $request->setUserResolver(fn () => $actor);
        foreach ((array) ($request->route()->defaults['economy_permissions'] ?? ['economy.order_manage']) as $permission) {
            app(DomainApiAuthorization::class)->authorize($request, $permission);
        }
    }
}
