<?php

namespace App\Modules\Contact\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Refresh native staff authority independently from Sanctum's narrowing abilities. */
class ContactApiAccess
{
    public static function permits(User $actor, string $permission): bool
    {
        return app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function authorize(Request $request, array $permissions, array $abilities = []): void
    {
        $actor = $request->user();
        foreach ($permissions as $permission) {
            abort_unless(self::permits($actor, $permission), 403, 'Missing Contact permission: '.$permission);
        }
        foreach ($abilities as $ability) {
            abort_unless($actor->tokenCan($ability), 403, 'Missing Contact token ability: '.$ability);
        }
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'getKey') && $token->getKey()) {
            return hash('sha256', 'token:'.$token->getKey());
        }
        // Sanctum's transient token is also used by Laravel's official test helper.
        abort_unless($token instanceof \Laravel\Sanctum\TransientToken, 401);

        return hash('sha256', 'session:'.($request->hasSession() ? $request->session()->getId() : 'sanctum-test'));
    }

    public function handle(Request $request, Closure $next)
    {
        $old = $request->user();
        $actor = $old ? User::find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal Contact actor is required.');
        $actor->withAccessToken($old->currentAccessToken());
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $d = $request->route()->defaults;
        self::authorize($request, $d['contact_permissions'] ?? [], $d['contact_abilities'] ?? []);
        if ($any = $d['contact_any_permissions'] ?? []) {
            abort_unless(collect($any)->contains(fn ($permission) => self::permits($actor, $permission)), 403, 'Contact form access is required.');
        }
        if ($request->boolean('send_customer_portal_invitation')) {
            self::authorize($request, ['customer_portal.invite'], ['contacts.portal_invite']);
        }
        if ($request->boolean('delete_if_orphan')) {
            self::authorize($request, ['contact.delete']);
        }

        return $next($request);
    }
}
