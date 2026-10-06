<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Security\Support;

use App\Models\Core\User;
use LogicException;

/**
 * Makes the one deliberate authentication cutover decision shared by every
 * protected writer. Unknown or mixed configuration is never a legacy alias.
 */
final class UserSecurityAuthCutover
{
    public function isEnforced(): bool
    {
        $guard = config('auth.guards.web');
        $provider = config('auth.providers.user_management');

        if (! is_array($guard)
            || ($guard['driver'] ?? null) !== 'session'
            || ($guard['provider'] ?? null) !== 'user_management'
            || ! is_array($provider)
            || ($provider['model'] ?? null) !== User::class) {
            throw new LogicException('The UserSecurity authentication cutover configuration is invalid.');
        }

        return match ($provider['driver'] ?? null) {
            'eloquent' => false,
            'nexum-user-security' => true,
            default => throw new LogicException(
                'The UserSecurity authentication provider driver is invalid.',
            ),
        };
    }
}
