<?php

namespace App\Modules\UserManagement\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Native current authority is mandatory in addition to an API ability. */
class UserManagementApiAccess
{
    public static function actor(Request $request): User
    {
        $old = $request->user();
        $actor = $old instanceof User ? User::query()->find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal user is required.');
        $token = $old->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->getKey() !== null) {
            $token = \Laravel\Sanctum\PersonalAccessToken::find($token->getKey());
            abort_unless($token && $token->tokenable_type === User::class && (int) $token->tokenable_id === $actor->id
                && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'Token unavailable.');
        }
        $actor->withAccessToken($token);
        $request->setUserResolver(fn () => $actor);
        Auth::setUser($actor);

        return $actor;
    }

    public static function allows(User $actor, string $permission): bool
    {
        return app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function authorize(User $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            abort_unless(self::allows($actor, $permission), 403, 'Missing user permission: '.$permission);
        }
    }

    public static function session(Request $request): void
    {
        abort_unless($request->hasSession() && ! $request->bearerToken()
            && Auth::guard('web')->check()
            && Auth::guard('web')->id() === $request->user()->id, 403,
            'protected_mutation_denied');
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'getKey') && $token->getKey()) {
            return hash('sha256', 'token:'.$token->getKey());
        }
        // Transient tokens exist only for actual session authentication (and test harnesses).
        abort_unless($request->hasSession() && $token instanceof \Laravel\Sanctum\TransientToken, 401);

        return hash_hmac('sha256', 'session:'.$request->session()->getId(), config('app.key'));
    }

    public function handle(Request $request, Closure $next)
    {
        $actor = self::actor($request);
        self::authorize($actor, $request->route()->defaults['um_permissions'] ?? []);
        if ($request->route()->defaults['um_session'] ?? false) {
            self::session($request);
        }

        return $next($request);
    }
}
