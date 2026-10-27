<?php

namespace App\Modules\System\Support;

use Illuminate\Validation\Rule;

/** Browser and API validate the same organization, theme and image inputs. */
final class CompanyProfileRules
{
    public static function company(): array
    {
        return [
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
        ];
    }

    public static function branding(): array
    {
        return [
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
        ];
    }

    public static function preset(): array
    {
        return ['preset' => ['required', 'string', Rule::in(array_keys(CompanyProfileSettings::getPresets()))]];
    }
}
