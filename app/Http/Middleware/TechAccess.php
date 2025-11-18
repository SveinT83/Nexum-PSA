<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class TechAccess
{
    /**
     * Ensure the user is authenticated and has Tech/Superuser role.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        // Require either Superuser or Tech role
        if (! $user->hasRole('Superuser') && ! $user->hasRole('Tech')) {
            abort(403, 'Ingen tilgang');
        }

        return $next($request);
    }
}
