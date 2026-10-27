<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use Illuminate\Validation\ValidationException;

/** Preserve the current profile confirmation semantics for both entry points. */
class ConfirmUserTwoFactor
{
    public function handle(User $user, #[\SensitiveParameter] string $code): void
    {
        try {
            if (! $user->verifyTwoFactorCode($code)) {
                throw ValidationException::withMessages(['code' => 'The provided two-factor code is invalid.']);
            }
            // Protected installations retain the owning database mutation guards.
            // No raw SQL, temporary guard removal or legacy fallback is permitted.
            $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        } finally {
            sodium_memzero($code);
        }
    }
}
