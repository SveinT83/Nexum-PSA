<?php

namespace App\Modules\Documentation\Support;

use App\Models\Core\User;
use App\Modules\CustomerPortal\Support\CustomerPortalContextResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Resolve only this user's current portal membership, with no fallback on a bad selection. */
class PortalDocumentationApiAccess
{
    public function handle(Request $request, Closure $next)
    {
        $old = $request->user();
        $actor = $old ? User::query()->find($old->id) : null;
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor(), 403);
        $actor->withAccessToken($old->currentAccessToken());
        Auth::setUser($actor);
        $request->setUserResolver(fn () => $actor);
        abort_unless($actor->tokenCan('documentation.portal.read'), 403);
        $request->validate(['membership_id' => 'sometimes|integer|min:1']);
        $preferred = $request->has('membership_id') ? $request->integer('membership_id')
            : ($request->hasSession() ? $request->session()->get('customer_portal_membership_id') : null);
        $context = app(CustomerPortalContextResolver::class)->resolveForUser($actor, $preferred);
        abort_unless($context && (! $preferred || (int) $context->membership->id === (int) $preferred), 403, 'No active customer portal access for this membership.');
        $request->attributes->set('customerPortalContext', $context);

        return $next($request);
    }
}
