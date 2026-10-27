<?php

namespace App\Modules\Signal\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Token abilities narrow fresh staff permissions; neither grants the other. */
class SignalApiAccess
{
    public static function actor(): User
    {
        $old = Auth::user();
        $actor = $old ? User::query()->find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal Signal actor is required.');
        $token = $old->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->getKey()) {
            $token = \Laravel\Sanctum\PersonalAccessToken::find($token->getKey());
            abort_unless($token && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'The API token is no longer active.');
        }
        $actor->withAccessToken($token);
        Auth::setUser($actor);

        return $actor;
    }

    public static function authorize(User $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            abort_unless(app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission),
                403, 'Missing Signal user permission: '.$permission);
        }
    }

    /** Edit/admin form reads must also work for narrow actors without index access. */
    public static function authorizeAny(User $actor, array $permissions): void
    {
        if ($permissions) {
            abort_unless(collect($permissions)->contains(fn ($permission) => app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission)), 403,
                'Missing Signal form access.');
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

    /** Receipt access always rechecks the original live action and provider authority. */
    public static function authorizeReceipt(Request $request, \App\Modules\Signal\Models\SignalApiCommand $command): void
    {
        $result = $command->result;
        self::authorize($request->user(), array_merge($result['permissions'], $result['extra_permissions'] ?? []));
        foreach ($result['source_email_ids'] ?? [] as $messageId) {
            $message = \App\Modules\Email\Models\EmailMessage::find($messageId);
            abort_unless($message && SignalApiExecutionPolicy::canReadSource($request, $message),
                403, 'Source mailbox access was revoked.');
        }
        foreach ([$result['ability'], ...($result['extra_abilities'] ?? [])] as $ability) {
            abort_unless($request->user()->tokenCan($ability), 403, 'Original command token authority was revoked.');
        }
    }

    public static function requireAbility(Request $request, string $ability): void
    {
        abort_unless($request->user()->tokenCan($ability), 403, 'Missing token ability: '.$ability);
        $request->attributes->set('signal_extra_abilities', array_values(array_unique([
            ...$request->attributes->get('signal_extra_abilities', []), $ability,
        ])));
    }

    public static function requirePermissions(Request $request, array $permissions): void
    {
        self::authorize($request->user(), $permissions);
        $request->attributes->set('signal_extra_permissions', array_values(array_unique([
            ...$request->attributes->get('signal_extra_permissions', []), ...$permissions,
        ])));
    }

    public function handle(Request $request, Closure $next)
    {
        $actor = self::actor();
        $request->setUserResolver(fn () => $actor);
        self::authorize($actor, $request->route()->defaults['signal_permissions'] ?? []);
        self::authorizeAny($actor, $request->route()->defaults['signal_any_permissions'] ?? []);

        return $next($request);
    }
}
