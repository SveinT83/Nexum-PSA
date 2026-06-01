<?php

namespace App\Modules\System\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'organization_number' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['nullable', 'string', 'max:160'],
            'address_line_2' => ['nullable', 'string', 'max:160'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'support_email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:60'],
            'website' => ['nullable', 'url', 'max:200'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $data['remove_logo'] = $request->boolean('remove_logo');

        $settings->update($data, $request->file('logo'));

        return back()->with('success', 'Company profile and branding were updated.');
    }
}
