<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        if (! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'Your user account is not active. Contact an administrator.']);
        }

        // Require an internal role or direct permissions; domain access is enforced per route.
        if (! $user->roles()->exists() && ! $user->permissions()->exists()) {
            abort(403, 'Ingen tilgang');
        }

        return $next($request);
    }
}
