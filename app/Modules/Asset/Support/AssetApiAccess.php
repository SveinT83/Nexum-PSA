<?php

namespace App\Modules\Asset\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Tokens can narrow current staff access; they cannot replace browser authorization. */
class AssetApiAccess
{
    public static function actor(?string $permission = null): User
    {
        $old = Auth::user();
        $actor = $old ? User::query()->find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal Asset actor is required.');
        $actor->withAccessToken($old->currentAccessToken());
        Auth::setUser($actor);
        if ($permission) {
            abort_unless(app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission), 403, 'Missing Asset user permission: '.$permission);
        }

        return $actor;
    }

    public function handle(Request $request, Closure $next)
    {
        $actor = self::actor();
        $request->setUserResolver(fn () => $actor);
        foreach ($request->route()->defaults['asset_permissions'] ?? [] as $permission) {
            self::actor($permission);
        }

        return $next($request);
    }
}
