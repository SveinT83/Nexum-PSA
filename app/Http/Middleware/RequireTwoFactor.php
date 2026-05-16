<?php

namespace App\Http\Middleware;

use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that enforces two-factor authentication for users
 * whose roles are listed in the enforce_two_factor_roles setting.
 *
 * If the user has a role that requires 2FA but hasn't confirmed their
 * 2FA setup yet, they are redirected to the security settings page
 * with a prompt to enable it.
 */
class RequireTwoFactor
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // If 2FA enforcement is disabled, pass through
        if (! $this->enforcementEnabled()) {
            return $next($request);
        }

        // Check if the user's roles require 2FA
        if (! $this->userRequiresTwoFactor($user)) {
            return $next($request);
        }

        // User already has confirmed 2FA? All good
        if ($user->hasConfirmedTwoFactor()) {
            return $next($request);
        }

        // Redirect to security settings with a message
        if (! $request->routeIs('tech.profile.security*')) {
            return redirect()->route('tech.profile.security')
                ->with('warning', 'Two-factor authentication is required for your role. Please enable it to continue.');
        }

        return $next($request);
    }

    /**
     * Check whether 2FA enforcement is enabled in settings.
     */
    protected function enforcementEnabled(): bool
    {
        $setting = \DB::table('common_settings')
            ->where('key', 'enforce_two_factor')
            ->value('value');

        return $setting === '1' || $setting === 'true';
    }

    /**
     * Check whether any of the user's roles require 2FA.
     */
    protected function userRequiresTwoFactor(User $user): bool
    {
        $rolesJson = \DB::table('common_settings')
            ->where('key', 'enforce_two_factor_roles')
            ->value('value');

        $requiredRoles = json_decode($rolesJson, true) ?? [];

        return $user->roles()->whereIn('name', $requiredRoles)->exists();
    }
}