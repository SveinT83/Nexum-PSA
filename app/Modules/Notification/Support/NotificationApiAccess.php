<?php

namespace App\Modules\Notification\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use App\Modules\CustomerPortal\Support\CustomerPortalContextResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Bearer abilities narrow current native identity and scope; never replace them. */
class NotificationApiAccess
{
    public function handle(Request $request, Closure $next)
    {
        $old = $request->user();
        $actor = $old ? User::find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor(), 403);
        $token = $old->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token = $token->fresh();
            abort_unless($token && (! $token->expires_at || $token->expires_at->isFuture()), 401);
        }
        $actor->withAccessToken($token);
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        $defaults = $request->route()->defaults;
        abort_unless($actor->tokenCan($defaults['notification_ability']), 403);
        if ($defaults['notification_portal'] ?? false) {
            $request->validate(['membership_id' => 'sometimes|integer|min:1']);
            $preferred = $request->has('membership_id') ? $request->integer('membership_id')
                : ($request->hasSession() ? $request->session()->get('customer_portal_membership_id') : null);
            $context = app(CustomerPortalContextResolver::class)->resolveForUser($actor, $preferred);
            abort_unless($context && (! $preferred || (int) $context->membership->id === (int) $preferred), 403);
            $request->attributes->set('customerPortalContext', $context);
        } elseif (! ($defaults['notification_legacy'] ?? false)) {
            abort_unless($actor->roles()->exists() || $actor->permissions()->exists(), 403);
        }
        if ($defaults['notification_admin'] ?? false) {
            abort_unless(app(EnforceTechRoutePermission::class)->allowsActor($actor, 'notification.manage_channels'), 403);
        }
        $allowed = $defaults['notification_fields'] ?? [];
        if ($defaults['notification_portal'] ?? false) {
            $allowed[] = 'membership_id';
        }
        \Illuminate\Support\Facades\Validator::make(['input' => $request->all()],
            ['input' => 'array'.($allowed ? ':'.implode(',', $allowed) : ''), ...($allowed ? [] : ['input' => 'array|max:0'])])->validate();

        try {
            return $next($request)->header('Cache-Control', 'private, no-store');
        } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            // Do not report transport-bearing SQL/exception text through ordinary diagnostics.
            return response()->json(['message' => 'Notification action is unavailable; use sanitized operation evidence.'], 503)
                ->header('Cache-Control', 'private, no-store');
        }
    }
}
