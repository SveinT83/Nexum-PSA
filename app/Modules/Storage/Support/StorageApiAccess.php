<?php

namespace App\Modules\Storage\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** A bearer ability narrows freshly loaded staff rights; it never creates them. */
class StorageApiAccess
{
    public static function actor(): User
    {
        $old = Auth::user();
        $actor = $old ? User::query()->find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal Storage actor is required.');
        $actor->withAccessToken($old->currentAccessToken());
        Auth::setUser($actor);

        return $actor;
    }

    public static function allows(User $actor, string $permission): bool
    {
        return app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function authorize(User $actor, array $permissions, array $any = []): void
    {
        foreach ($permissions as $permission) {
            abort_unless(self::allows($actor, $permission), 403, 'Missing Storage user permission: '.$permission);
        }
        if ($any) {
            abort_unless(collect($any)->contains(fn ($permission) => self::allows($actor, $permission)), 403, 'Missing Storage reference access.');
        }
    }

    /** PAT ids keep the existing identity; stateful Sanctum tokens use their session fingerprint. */
    public static function tokenHash(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && method_exists($token, 'getKey')) {
            return hash('sha256', (string) ($token->getKey() ?? 'session'));
        }
        abort_unless($token instanceof \Laravel\Sanctum\TransientToken && $request->hasSession(), 401,
            'Bearer authentication or an authenticated session is required.');

        return hash('sha256', 'session:'.$request->session()->getId());
    }

    /** Retained responses must recheck every extra authority used for their projection. */
    public static function retainResponseAuthority(Request $request, array $permissions, array $abilities): void
    {
        $request->attributes->set('storage_response_permissions', array_values(array_unique(array_merge(
            $request->attributes->get('storage_response_permissions', []), $permissions))));
        $request->attributes->set('storage_response_abilities', array_values(array_unique(array_merge(
            $request->attributes->get('storage_response_abilities', []), $abilities))));
    }

    public function handle(Request $request, Closure $next)
    {
        $actor = self::actor();
        $request->setUserResolver(fn () => $actor);
        $defaults = $request->route()->defaults;
        self::authorize($actor, $defaults['storage_permissions'] ?? [], $defaults['storage_any_permissions'] ?? []);

        return $next($request);
    }
}
