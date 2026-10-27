<?php

namespace App\Modules\CustomField\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

/** Native management and field visibility remain independent from bearer grants. */
class CustomFieldApiAccess
{
    public static function allows(User $actor, string $permission): bool
    {
        return app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission);
    }

    public static function manager(User $actor): bool
    {
        return self::allows($actor, 'customfield.manage_settings');
    }

    public static function authorize(Request $request): User
    {
        $previous = $request->user();
        $actor = $previous ? User::query()->find($previous->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && ($actor->roles()->exists() || $actor->permissions()->exists()), 403, 'An active internal actor is required.');
        $token = $previous->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->getKey()) {
            $token = PersonalAccessToken::find($token->getKey());
            abort_unless($token && $token->tokenable_id == $actor->id && $token->tokenable_type === $actor->getMorphClass()
                && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'The API token is no longer available.');
        }
        $actor->withAccessToken($token);
        $request->setUserResolver(fn () => $actor);
        $ability = $request->route()->defaults['customfield_ability'];
        abort_unless($actor->tokenCan($ability), 403, 'The API token lacks the required ability.');
        if ($request->route()->defaults['customfield_manage'] ?? false) {
            abort_unless(self::manager($actor), 403, 'Custom Field management permission is required.');
        } else {
            abort_unless(self::manager($actor) || self::allows($actor, 'client.view') || self::allows($actor, 'ticket.view'),
                403, 'A supported domain view permission is required.');
        }

        return $actor;
    }

    public static function canDiscover(User $actor, CustomFieldDefinition $definition): bool
    {
        if (self::manager($actor)) {
            return true;
        }
        $alias = app(CustomFieldModelRegistry::class)->aliasFor((string) $definition->model_type);
        $permission = match ($alias) {
            'client', 'client_site' => 'client.view', 'ticket' => 'ticket.view', default => null
        };
        if (! $permission || ! $definition->active || ! self::allows($actor, $permission)) {
            return false;
        }
        if ($definition->admin_only && ! $actor->hasAnyRole(['Admin', 'Superuser'])) {
            return false;
        }

        return ! $definition->view_permission || $actor->can($definition->view_permission);
    }

    public static function tokenHash(Request $request): string
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->getKey()) {
            return hash('sha256', 'token:'.$token->getKey());
        }
        abort_unless($token instanceof TransientToken && $request->hasSession(), 401, 'Use a bearer token or authenticated session for commands.');

        return hash('sha256', 'session:'.$request->session()->getId());
    }

    public function handle(Request $request, Closure $next)
    {
        self::authorize($request);

        return $next($request);
    }
}
