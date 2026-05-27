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
        $enforceTwoFactor = \DB::table('common_settings')
            ->where('name', 'enforce_two_factor')
            ->value('value') ?? '0';

        $rolesJson = \DB::table('common_settings')
            ->where('name', 'enforce_two_factor_roles')
            ->value('json') ?? '[]';

        $enforcedRoles = json_decode($rolesJson, true) ?? [];

        // Get all available roles for the multi-select
        $allRoles = \Spatie\Permission\Models\Role::pluck('name')->toArray();

        return view('usermanagement::Admin.two-factor-settings', [
            'enforceTwoFactor' => $enforceTwoFactor === '1' || $enforceTwoFactor === 'true',
            'enforcedRoles' => $enforcedRoles,
            'allRoles' => $allRoles,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enforce_two_factor' => 'nullable|boolean',
            'enforce_two_factor_roles' => 'nullable|array',
            'enforce_two_factor_roles.*' => 'string|exists:roles,name',
        ]);

        $enforce = isset($validated['enforce_two_factor']) && $validated['enforce_two_factor'];

        \DB::table('common_settings')->updateOrInsert(
            ['name' => 'enforce_two_factor'],
            [
                'type' => 'security',
                'description' => 'Require two-factor authentication for selected roles.',
                'value' => $enforce ? '1' : '0',
                'json' => null,
            ]
        );

        \DB::table('common_settings')->updateOrInsert(
            ['name' => 'enforce_two_factor_roles'],
            [
                'type' => 'security',
                'description' => 'Role names that must use two-factor authentication when enforcement is enabled.',
                'value' => null,
                'json' => json_encode($validated['enforce_two_factor_roles'] ?? []),
            ]
        );

        return redirect()->route('tech.admin.user_management.2fa-settings')
            ->with('success', 'Two-factor authentication settings updated.');
    }
}
