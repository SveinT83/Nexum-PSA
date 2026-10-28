<?php

namespace App\Modules\Nextcloud\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/** Re-read native identity and the original API scopes immediately before a Talk effect. */
final class TalkActionAuthority
{
    public static function allows(?int $actorId, array $permissions): bool
    {
        $actor = $actorId ? User::find($actorId) : null;
        if (! $actor || ! $actor->isActive() || $actor->isSystemActor()) {
            return false;
        }
        $gate = app(EnforceTechRoutePermission::class);
        foreach ($permissions as $permission) {
            if (! $gate->allowsActor($actor, $permission)) {
                return false;
            }
        }
        if (request()->is('api/*')) {
            $original = Auth::user();
            $token = $original?->currentAccessToken();
            if ((int) $original?->id !== (int) $actorId || ! $token instanceof PersonalAccessToken) {
                return false;
            }
            $token = $token->fresh();
            if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
                return false;
            }
            $defaults = request()->route()?->defaults ?? [];
            $abilities = $defaults['nextcloud_abilities'] ?? [$defaults['notification_ability'] ?? 'notifications.channels.test'];
            foreach ($abilities as $ability) {
                if (! $token->can($ability)) {
                    return false;
                }
            }
        }

        return true;
    }
}
