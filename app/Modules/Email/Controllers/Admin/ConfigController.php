<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Email\Services\EmailConfigurationAdministration;
use Illuminate\Http\Request;

/** The browser owns rendering; the shared service owns validation and domain settings. */
class ConfigController extends Controller
{
    public function index(Request $request, EmailConfigurationAdministration $settings)
    {
        return view('email::Admin.Config.index', app()->call([$settings, 'index'], ['request' => $request]));
    }

    public function update(Request $request, EmailConfigurationAdministration $settings)
    {
        $settings->update($request);

        return redirect()->route('tech.admin.settings.email.config')->with('status', 'Email configuration updated successfully.');
    }
}
