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
            ->where('key', 'enforce_two_factor')
            ->value('value') ?? '0';

        $rolesJson = \DB::table('common_settings')
            ->where('key', 'enforce_two_factor_roles')
            ->value('value') ?? '[]';

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
            ['key' => 'enforce_two_factor'],
            ['value' => $enforce ? '1' : '0', 'updated_at' => now()]
        );

        \DB::table('common_settings')->updateOrInsert(
            ['key' => 'enforce_two_factor_roles'],
            ['value' => json_encode($validated['enforce_two_factor_roles'] ?? []), 'updated_at' => now()]
        );

        return redirect()->route('tech.admin.user_management.2fa-settings')
            ->with('success', 'Two-factor authentication settings updated.');
    }
}