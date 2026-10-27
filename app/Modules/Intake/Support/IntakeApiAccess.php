<?php

namespace App\Modules\Intake\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/** Token scopes are a ceiling on live native actor rights, never a replacement for them. */
class IntakeApiAccess
{
    public static function permits(User $actor, string $permission): bool
    {
        return app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function require(User $actor, string $permission): void
    {
        abort_unless(self::permits($actor, $permission), 403, 'Missing Intake user permission: '.$permission);
    }

    public static function branch(Request $request, array $branches): array
    {
        foreach ($branches as [$ability, $permission]) {
            if ($request->user()->tokenCan($ability) && self::permits($request->user(), $permission)) {
                return [$ability, $permission];
            }
        }
        abort(403, 'The token and actor do not permit this Intake operation.');
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->getKey()) {
            return hash('sha256', (string) $token->getKey());
        }
        abort_unless($token instanceof TransientToken && $request->hasSession(), 401);

        return hash('sha256', 'session:'.$request->session()->getId());
    }

    public function handle(Request $request, Closure $next)
    {
        $old = $request->user();
        $token = $old?->currentAccessToken();
        if ($bearer = $request->bearerToken()) {
            $token = PersonalAccessToken::findToken($bearer);
            abort_unless($token && $token->tokenable instanceof User
                && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'The API token is no longer active.');
            $expiry = config('sanctum.expiration');
            abort_if($expiry && $token->created_at->lte(now()->subMinutes($expiry)), 401);
            $actor = User::find($token->tokenable_id);
        } else {
            $actor = $old ? User::find($old->id) : null;
        }
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal Intake actor is required.');
        $actor->withAccessToken($token);
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('intake_api', true);
        $branches = $request->route()->defaults['intake_branches'] ?? [];
        if ($branches) {
            $request->attributes->set('intake_authority', self::branch($request, $branches));
        }

        return $next($request);
    }
}
