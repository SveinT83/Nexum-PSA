<?php

namespace App\Modules\CustomerPortal\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Actions\AcceptCustomerPortalInvitation;
use App\Modules\CustomerPortal\Models\CustomerPortalInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CustomerPortalInvitationController extends Controller
{
    public function show(string $token, AcceptCustomerPortalInvitation $acceptInvitation): View
    {
        $invitation = CustomerPortalInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isValid()) {
            return view('customerportal::Public.invitations.expired');
        }

        return view('customerportal::Public.invitations.accept', [
            'token' => $token,
            'invitation' => $invitation,
            'passwordRequired' => $acceptInvitation->passwordRequired($invitation),
        ]);
    }

    public function store(Request $request, string $token, AcceptCustomerPortalInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = CustomerPortalInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isValid()) {
            return redirect()->route('customer-portal.invitations.accept', ['token' => $token])
                ->with('error', 'This portal invitation is invalid or expired.');
        }

        $passwordRequired = $acceptInvitation->passwordRequired($invitation);

        $validated = $request->validate([
            'password' => [$passwordRequired ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $result = $acceptInvitation->handle($invitation, $validated['password'] ?? null);

        Auth::login($result['user']);
        $request->session()->regenerate();
        $request->session()->put('customer_portal_membership_id', $result['membership']->id);

        return redirect()->route('customer-portal.dashboard')
            ->with('success', 'Customer portal access is active.');
    }
}
