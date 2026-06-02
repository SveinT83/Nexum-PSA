<?php

namespace App\Modules\System\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\System\Support\CompanyProfileSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        ]);

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
        $data = $request->validate([
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'default_theme' => ['required', Rule::in(['light', 'dark', 'system'])],
            'light_header_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_header_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_footer_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_footer_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_left_sidebar_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_left_sidebar_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_main_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_right_sidebar_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_right_sidebar_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_page_header_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_page_header_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_card_header_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_card_header_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_content_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_primary_button_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_primary_button_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_secondary_button_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'light_secondary_button_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_header_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_header_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_footer_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_footer_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_left_sidebar_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_left_sidebar_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_main_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_right_sidebar_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_right_sidebar_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_page_header_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_page_header_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_card_header_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_card_header_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_content_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_primary_button_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_primary_button_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_secondary_button_background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'dark_secondary_button_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'logo_light' => ['nullable', 'image', 'max:2048'],
            'logo_dark' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_light_logo' => ['nullable', 'boolean'],
            'remove_dark_logo' => ['nullable', 'boolean'],
        ]);

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
}
