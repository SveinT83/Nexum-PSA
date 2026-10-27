<?php

namespace App\Modules\UserManagement\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin controller for 2FA enforcement settings.
 *
 * Controls whether two-factor authentication is required for specific roles
 * across the platform.
 */
class TwoFactorSettingsController extends Controller
{
    public function show(): View
    {
        $data = app(\App\Modules\UserManagement\Actions\UserTwoFactorPolicy::class)->read();

        return view('usermanagement::Admin.two-factor-settings', [
            'enforceTwoFactor' => $data['enforce_two_factor'],
            'enforcedRoles' => $data['enforce_two_factor_roles'], 'allRoles' => $data['roles'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        app(\App\Modules\UserManagement\Actions\UserTwoFactorPolicy::class)->update($request);

        return redirect()->route('tech.admin.user_management.2fa-settings')
            ->with('success', 'Two-factor authentication settings updated.');
    }
}
