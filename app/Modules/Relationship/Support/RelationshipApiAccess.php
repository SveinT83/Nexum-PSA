<?php

namespace App\Modules\Relationship\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Match native AdminAccess: active internal actors and explicit operation rights. */
class RelationshipApiAccess
{
    public static function actor(): User
    {
        $previous = Auth::user();
        $actor = $previous ? User::query()->find($previous->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'Active internal Relationship access is required.');
        $actor->withAccessToken($previous->currentAccessToken());
        Auth::setUser($actor);

        return $actor;
    }

    public static function permit(User $actor, string $permission): void
    {
        abort_unless(collect(explode('|', $permission))->contains(fn ($right) => app(EnforceTechRoutePermission::class)->allowsActor($actor, $right)), 403, 'Missing Relationship operation authority.');
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'getKey') && $token->getKey()) {
            return hash('sha256', (string) $token->getKey());
        }
        abort_unless($token instanceof \Laravel\Sanctum\TransientToken && $request->hasSession(), 401, 'An authenticated token or session is required.');

        return hash('sha256', 'session:'.$request->session()->getId());
    }

    public function handle(Request $request, Closure $next)
    {
        $actor = self::actor();
        $request->setUserResolver(fn () => $actor);
        self::permit($actor, $request->route()->defaults['relationship_permission']);
        abort_if(array_diff(array_keys($request->all()), $request->route()->defaults['relationship_fields'] ?? []), 422, 'Unsupported Relationship input fields.');

        return app(RelationshipApiPrivacy::class)->handle($request, $next);
    }
}
