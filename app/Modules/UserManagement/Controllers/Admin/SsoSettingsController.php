<?php

namespace App\Modules\UserManagement\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Sso\SsoProvider;
use App\Modules\UserManagement\Sso\SsoSettings;
use Illuminate\Http\Request;

/** Reuse the existing account-security administration permission. */
class SsoSettingsController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->can('user.manage_2fa'), 403);

        return view('usermanagement::Admin.sso', ['provider' => SsoProvider::find(1)]);
    }

    public function update(Request $request, SsoSettings $settings)
    {
        abort_unless($request->user()->can('user.manage_2fa'), 403);
        $settings->save($request);

        return redirect()->route('tech.admin.user_management.sso')
            ->with('success', 'Settings saved and provider discovery verified. A real work-account login still needs verification.');
    }
}
