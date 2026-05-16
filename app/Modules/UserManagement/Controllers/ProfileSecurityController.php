<?php

namespace App\Modules\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * User profile security settings.
 *
 * Lets the authenticated user enable/disable two-factor authentication,
 * view their QR code, and regenerate recovery codes.
 */
class ProfileSecurityController extends Controller
{
    /**
     * Show the security settings page.
     */
    public function show(): View
    {
        $user = auth()->user();

        return view('usermanagement::profile.security', [
            'user' => $user,
            'twoFactorEnabled' => ! is_null($user->two_factor_secret),
            'twoFactorConfirmed' => ! is_null($user->two_factor_confirmed_at),
        ]);
    }

    /**
     * Enable two-factor authentication.
     *
     * Generates the secret and recovery codes, but the user must confirm
     * by providing a valid TOTP code before it takes effect.
     */
    public function enable(Request $request, EnableTwoFactorAuthentication $action): RedirectResponse
    {
        $action->handle($request->user());

        return redirect()->route('tech.profile.security')
            ->with('status', 'two-factor-enabled');
    }

    /**
     * Confirm two-factor authentication by verifying the OTP code.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();

        // Verify the provided code against the stored secret
        if (! $user->verifyTwoFactorCode($request->input('code'))) {
            return back()->withErrors(['code' => 'The provided two-factor code is invalid.']);
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        return redirect()->route('tech.profile.security')
            ->with('status', 'two-factor-confirmed');
    }

    /**
     * Disable two-factor authentication.
     */
    public function disable(Request $request, DisableTwoFactorAuthentication $action): RedirectResponse
    {
        $action->handle($request->user());

        return redirect()->route('tech.profile.security')
            ->with('status', 'two-factor-disabled');
    }

    /**
     * Generate new recovery codes (existing ones are invalidated).
     */
    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $action): RedirectResponse
    {
        $action->handle($request->user());

        return redirect()->route('tech.profile.security')
            ->with('status', 'recovery-codes-regenerated');
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return redirect()->route('tech.profile.security')
            ->with('status', 'password-updated');
    }
}