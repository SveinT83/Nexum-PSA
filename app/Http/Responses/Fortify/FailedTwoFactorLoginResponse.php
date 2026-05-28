<?php

namespace App\Http\Responses\Fortify;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse as FailedTwoFactorLoginResponseContract;

class FailedTwoFactorLoginResponse implements FailedTwoFactorLoginResponseContract
{
    /**
     * Return a clearer response when the TOTP challenge fails or its session has expired.
     */
    public function toResponse($request)
    {
        $request = $request instanceof Request ? $request : request();
        $hasChallengeSession = $request->session()->has('login.id');

        if (! $hasChallengeSession) {
            $message = __('Your two-factor login session expired. Please sign in again and enter the current TOTP code.');

            Log::warning('Two-factor login failed because the challenge session was missing.', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'route' => $request->route()?->getName(),
            ]);

            if ($request->wantsJson()) {
                throw ValidationException::withMessages([
                    'email' => [$message],
                ]);
            }

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        [$key, $message] = $request->filled('recovery_code')
            ? ['recovery_code', __('The provided two-factor recovery code was invalid.')]
            : ['code', __('The provided TOTP code was invalid. Check that your device time is correct and try the newest code.')];

        Log::warning('Two-factor login failed because the submitted code was invalid.', [
            'user_id' => $request->session()->get('login.id'),
            'method' => $request->filled('recovery_code') ? 'recovery_code' : 'totp',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if ($request->wantsJson()) {
            throw ValidationException::withMessages([
                $key => [$message],
            ]);
        }

        return redirect()->route('two-factor.login')->withErrors([$key => $message]);
    }
}
