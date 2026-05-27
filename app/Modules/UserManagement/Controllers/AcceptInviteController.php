<?php

namespace App\Modules\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\UserManagement\Models\InviteToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Handles the public invite acceptance flow.
 *
 * Unauthenticated users click the invite link → see a set-password form →
 * choose a password → their account is activated.
 */
class AcceptInviteController extends Controller
{
    /**
     * Show the invite acceptance form.
     *
     * Validates the token and shows the password setup screen.
     * If the token is invalid or expired, show an error with option to request re-send.
     */
    public function show(string $token): View
    {
        $inviteToken = InviteToken::where('token', $token)->first();

        if (! $inviteToken || ! $inviteToken->isValid()) {
            return view('usermanagement::invite.expired');
        }

        $user = $inviteToken->user;

        return view('usermanagement::invite.accept', [
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Process the invite acceptance — set password and activate user.
     */
    public function store(Request $request, string $token): RedirectResponse
    {
        $inviteToken = InviteToken::where('token', $token)->first();

        if (! $inviteToken || ! $inviteToken->isValid()) {
            return redirect()->route('invite.expired')
                ->with('error', 'This invitation link is invalid or has expired.');
        }

        $validated = $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $inviteToken->user;

        // Set password and activate the user
        $user->update([
            'password' => Hash::make($validated['password']),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        // Mark token as used
        $inviteToken->markUsed();

        // Log the user in
        auth()->login($user);

        return redirect()->route('tech.dashboard')
            ->with('success', 'Your account has been activated. Welcome!');
    }
}