<?php

namespace App\Modules\UserManagement\Sso;

use App\Models\Core\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/** Shared local account authority; upstream claims cannot grant internal roles or step-up. */
class SsoAccess
{
    public function allowed(?User $user): bool
    {
        return $user && $user->isActive() && ! $user->isSystemActor()
            && ($user->roles()->exists() || $user->permissions()->exists());
    }

    public function fingerprint(User $user): string
    {
        return hash_hmac('sha256', json_encode([
            $user->getKey(), $user->getAuthPassword(), $user->two_factor_secret,
            $user->two_factor_confirmed_at, $user->auth_security_epoch,
            $user->two_factor_generation_id, config('auth.providers.user_management.driver'),
        ], JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function reauthenticate(Request $request): User
    {
        $user = $request->user()?->fresh();
        $password = $request->input('current_password');
        $code = $request->input('code');
        try {
            abort_unless($this->allowed($user) && is_string($password)
                && Hash::check($password, $user->getAuthPassword()), 403, 'Account verification failed.');
            if ($user->hasConfirmedTwoFactor()) {
                $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
                try {
                    abort_unless(is_string($code) && app(TwoFactorAuthenticationProvider::class)
                        ->verify($secret, $code), 403, 'Account verification failed.');
                } finally {
                    sodium_memzero($secret);
                }
            }

            return $user;
        } finally {
            $request->request->remove('current_password');
            $request->request->remove('code');
            if (is_string($password)) {
                sodium_memzero($password);
            }
            if (is_string($code)) {
                sodium_memzero($code);
            }
        }
    }

    public static function identityKey(string $issuer, string $subject): string
    {
        return hash('sha256', $issuer."\0".$subject);
    }
}
