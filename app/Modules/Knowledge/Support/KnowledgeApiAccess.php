<?php

namespace App\Modules\Knowledge\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Token abilities narrow fresh staff permissions; neither grants the other. */
class KnowledgeApiAccess
{
    public static function actor(): User
    {
        $old = Auth::user();
        $actor = $old ? User::query()->find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal Knowledge actor is required.');
        $actor->withAccessToken($old->currentAccessToken());
        Auth::setUser($actor);

        return $actor;
    }

    public static function authorize(User $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            abort_unless(app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission),
                403, 'Missing Knowledge user permission: '.$permission);
        }
    }

    /** Edit/admin form reads must also work for narrow actors without index access. */
    public static function authorizeAny(User $actor, array $permissions): void
    {
        if ($permissions) {
            abort_unless(collect($permissions)->contains(fn ($permission) => app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission)), 403,
                'Missing Knowledge form access.');
        }
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && method_exists($token, 'getKey') && $token->getKey()) {
            return hash('sha256', (string) $token->getKey());
        }
        abort_unless($token instanceof \Laravel\Sanctum\TransientToken && $request->hasSession(),
            401, 'A bearer token or authenticated session is required.');

        return hash('sha256', 'session:'.$request->session()->getId());
    }

    public function handle(Request $request, Closure $next)
    {
        $actor = self::actor();
        $request->setUserResolver(fn () => $actor);
        self::authorize($actor, $request->route()->defaults['knowledge_permissions'] ?? []);
        self::authorizeAny($actor, $request->route()->defaults['knowledge_any_permissions'] ?? []);

        $defaults = $request->route()->defaults;
        abort_unless($actor->tokenCan($defaults['knowledge_ability']), 403);
        if ($revision = $request->route('revision')) {
            $access = app(KnowledgeRevisionAccess::class);
            if (($defaults['knowledge_revision_access'] ?? null) === 'view') {
                abort_unless($access->canView($actor, $revision), 403);
            } elseif (($defaults['knowledge_revision_access'] ?? null) === 'approve') {
                abort_unless($access->canApprove($actor, $revision), 403);
            }
        }

        return $next($request);
    }
}
