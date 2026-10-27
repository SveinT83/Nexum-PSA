<?php

namespace App\Modules\Marketing\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Token capabilities only narrow current staff authority, including command replay. */
class MarketingApiAccess
{
    public static function allows(User $actor, string $permission): bool
    {
        return app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function authorize(User $actor, array $permissions): void
    {
        abort_unless($actor->isActive() && ! $actor->isSystemActor(), 403, 'An active internal Marketing actor is required.');
        foreach ($permissions as $permission) {
            abort_unless(app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission), 403, 'Missing Marketing permission: '.$permission);
        }
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'getKey') && $token->getKey()) {
            return hash('sha256', (string) $token->getKey());
        }
        abort_unless($request->hasSession(), 401, 'A bearer token or authenticated session is required.');

        return hash('sha256', 'session:'.$request->session()->getId());
    }

    public function handle(Request $request, Closure $next)
    {
        $previous = $request->user();
        $actor = $previous ? User::find($previous->id) : null;
        abort_unless($actor, 403);
        $token = $previous->currentAccessToken();
        if ($token && method_exists($token, 'getKey') && $token->getKey()) {
            $token = \Laravel\Sanctum\PersonalAccessToken::find($token->getKey());
            abort_unless($token && (int) $token->tokenable_id === (int) $actor->id && $token->tokenable_type === $actor->getMorphClass()
                && (! $token->expires_at || $token->expires_at->isFuture())
                && (! config('sanctum.expiration') || $token->created_at->gt(now()->subMinutes(config('sanctum.expiration')))), 401, 'Marketing token is expired or revoked.');
        }
        $actor->withAccessToken($token);
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $defaults = $request->route()->defaults;
        self::authorize($actor, $defaults['marketing_permissions'] ?? []);
        abort_unless($actor->tokenCan($defaults['marketing_ability']), 403, 'Missing Marketing token scope.');
        if ($defaults['marketing_provider'] ?? false) {
            abort_unless($actor->tokenCan('marketing.providers.execute'), 403, 'Provider execution scope is required.');
            abort_unless(config('marketing.api_provider_execution_enabled', false), 409, 'Marketing API provider execution is disabled.');
        }

        return $next($request);
    }
}
