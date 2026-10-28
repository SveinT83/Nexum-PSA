<?php

namespace App\Modules\Notification\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/** Current installation administration is independent of browser device consent or event subscriptions. */
final class WebPushSetupAccess
{
    public static function actor(Request $request): User
    {
        $old = Auth::user();
        $actor = $old ? User::find($old->id) : null;
        abort_unless($actor && self::allows($actor), 403, 'Current Notification channel administration is required.');
        $token = $old->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token = $token->fresh();
            abort_unless($token && (string) $token->tokenable_id === (string) $actor->id
                && $token->tokenable_type === $actor->getMorphClass()
                && (! $token->expires_at || $token->expires_at->isFuture()), 401, 'The API credential is no longer active.');
        }
        $actor->withAccessToken($token);
        if ($request->is('api/*')) {
            $ability = $request->route()?->defaults['notification_ability'] ?? null;
            abort_unless($token instanceof PersonalAccessToken && is_string($ability) && $token->can($ability), 403, 'Current setup API scope is required.');
        }
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);

        return $actor;
    }

    public static function allows(?User $actor): bool
    {
        return $actor && $actor->isActive() && ! $actor->isSystemActor()
            && app(EnforceTechRoutePermission::class)->allowsActor($actor, 'notification.manage_channels');
    }
}
