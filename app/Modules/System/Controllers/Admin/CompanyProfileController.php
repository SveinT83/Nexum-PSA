<?php

namespace App\Modules\System\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\System\Support\CompanyProfileRules;
use App\Modules\System\Support\CompanyProfileSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyProfileController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Company profile and branding
    |--------------------------------------------------------------------------
    |
    | Admins configure the organization identity shown in the tech shell.
    | The controller stays thin and delegates persistence to the System support
    | class that owns the common_settings payload.
    |
    */
    public function edit(CompanyProfileSettings $settings): View
    {
        return view('system::Admin.CompanyProfile.edit', [
            'companyProfile' => $settings->get(),
        ]);
    }

    public function update(Request $request, CompanyProfileSettings $settings): RedirectResponse
    {
        $data = $request->validate(CompanyProfileRules::company());

        $settings->update($data);

        return back()->with('success', 'Company profile was updated.');
    }

    public function editBranding(CompanyProfileSettings $settings): View
    {
        return view('system::Admin.Branding.edit', [
            'companyProfile' => $settings->get(),
        ]);
    }

    public function updateBranding(Request $request, CompanyProfileSettings $settings): RedirectResponse
    {
        $data = $request->validate(CompanyProfileRules::branding());

        $data['remove_logo'] = $request->boolean('remove_logo');
        $data['remove_light_logo'] = $request->boolean('remove_light_logo');
        $data['remove_dark_logo'] = $request->boolean('remove_dark_logo');

        $settings->update(
            $data,
            $request->file('logo'),
            $request->file('logo_light'),
            $request->file('logo_dark'),
        );

        return back()->with('success', 'Branding was updated.');
    }

    public function resetBranding(CompanyProfileSettings $settings): RedirectResponse
    {
        $settings->resetBranding();

        return back()->with('success', 'Branding was reset to defaults.');
    }

    public function applyThemePreset(Request $request, CompanyProfileSettings $settings): RedirectResponse
    {
        $presetKey = $request->validate(CompanyProfileRules::preset())['preset'];

        $settings->applyPreset($presetKey);

        return back()->with('success', 'Theme preset was applied.');
    }
}
