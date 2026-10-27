<?php

namespace App\Http\Middleware;

use App\Models\Core\User;
use App\Modules\UserManagement\Sso\SsoSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that enforces two-factor authentication for users
 * whose roles are listed in the enforce_two_factor_roles setting.
 *
 * If the user has a role that requires 2FA but hasn't confirmed their
 * 2FA setup yet, they are redirected to the security settings page unless
 * their current, validated SSO session delegates login MFA to the provider.
 */
class RequireTwoFactor
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->requiresEnrollment($request)) {
            return $next($request);
        }

        // Redirect to security settings with a message
        if (! $request->routeIs('tech.profile.security*')) {
            return redirect()->route('tech.profile.security')
                ->with('warning', 'Two-factor authentication is required for your role. Please enable it to continue.');
        }

        return $next($request);
    }

    /** Shared web/API enrollment decision; only a current verified SSO grant substitutes. */
    public function requiresEnrollment(Request $request): bool
    {
        $user = $request->user()?->fresh();
        if (! $user || ! $this->enforcementEnabled() || ! $this->userRequiresTwoFactor($user)
            || $user->hasConfirmedTwoFactor()) {
            return false;
        }
        $grant = $request->hasSession() ? $request->session()->get('sso.grant') : null;

        return ! (is_string($grant) && app(SsoSessions::class)->valid($grant, $user));
    }

    /**
     * Check whether 2FA enforcement is enabled in settings.
     */
    protected function enforcementEnabled(): bool
    {
        $setting = \DB::table('common_settings')
            ->where('name', 'enforce_two_factor')
            ->value('value');

        return $setting === '1' || $setting === 'true';
    }

    /**
     * Check whether any of the user's roles require 2FA.
     */
    protected function userRequiresTwoFactor(User $user): bool
    {
        $rolesJson = \DB::table('common_settings')
            ->where('name', 'enforce_two_factor_roles')
            ->value('json');

        $requiredRoles = json_decode($rolesJson, true) ?? [];

        return $user->roles()->whereIn('name', $requiredRoles)->exists();
    }
}
