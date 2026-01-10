<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminAccess
{
    /**
     * Ensure the user is authenticated and has Admin role.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->hasRole('Superuser') && ! $user->hasRole('Admin')) {
            abort(403, 'No access');
        }

        return $next($request);
    }
}
