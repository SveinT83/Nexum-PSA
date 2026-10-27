<?php

namespace App\Modules\Booking\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** A bearer ability narrows the caller's current native authority. */
class BookingApiAccess
{
    public static function actor(Request $request): User
    {
        $old = $request->user();
        $actor = $old ? User::query()->find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'Active internal Booking access is required.');
        $actor->withAccessToken($old->currentAccessToken());
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);

        return $actor;
    }

    public static function authorize(User $actor, string $permission): void
    {
        abort_unless(app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission), 403, 'Missing Booking permission.');
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'getKey') && $token->getKey()) {
            return hash('sha256', 'pat:'.$token->getKey());
        }
        abort_unless($token instanceof \Laravel\Sanctum\TransientToken && $request->hasSession(), 401, 'A bearer or authenticated session is required.');

        return hash('sha256', 'session:'.$request->session()->getId());
    }

    public function handle(Request $request, Closure $next)
    {
        $actor = self::actor($request);
        $permission = $request->route()->defaults['booking_permission'] ?? null;
        if ($permission) {
            self::authorize($actor, $permission);
        }

        return $next($request);
    }
}
