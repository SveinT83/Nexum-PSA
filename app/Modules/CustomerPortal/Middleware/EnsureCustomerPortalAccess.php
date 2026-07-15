<?php

namespace App\Modules\CustomerPortal\Middleware;

use App\Modules\CustomerPortal\Support\CustomerPortalContextResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureCustomerPortalAccess
{
    public function __construct(private readonly CustomerPortalContextResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'Your user account is not active. Contact an administrator.']);
        }

        $context = $this->resolver->resolveForUser($user, $request->session()->get('customer_portal_membership_id'));

        if (! $context) {
            abort(403, 'No active customer portal access.');
        }

        $request->session()->put('customer_portal_membership_id', $context->membership->id);
        $request->attributes->set('customerPortalContext', $context);

        return $next($request);
    }
}
