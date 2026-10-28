<?php

namespace App\Modules\CustomerPortal\Middleware;

use App\Models\Core\User;
use App\Modules\CustomerPortal\Support\CustomerPortalContextResolver;
use Closure;
use Illuminate\Http\Request;

/** Sanctum authenticates the existing User; current Portal account/contact/membership still authorize every request. */
class RequireCustomerPortalApiContext
{
    public function handle(Request $request, Closure $next)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isActive(), 403, 'An active customer identity is required.');
        $data = $request->validate(['membership_id' => 'sometimes|integer|min:1']);
        $id = isset($data['membership_id']) ? (int) $data['membership_id'] : null;
        $context = app(CustomerPortalContextResolver::class)->resolveForUser($actor, $id);
        abort_unless($context && ($id === null || (int) $context->membership->id === $id), 403, 'The selected active customer membership is unavailable.');
        $request->attributes->set('customerPortalContext', $context);

        return $next($request);
    }
}
