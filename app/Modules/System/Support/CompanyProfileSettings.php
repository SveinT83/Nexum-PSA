<?php

namespace App\Modules\System\Support;

use App\Models\Settings\CommonSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CompanyProfileSettings
{
    private const TYPE = 'company_profile';

    private const NAME = 'branding';

    private const DEFAULTS = [
        'company_name' => 'Nexum PSA',
        'legal_name' => null,
        'organization_number' => null,
        'address_line_1' => null,
        'address_line_2' => null,
        'postal_code' => null,
        'city' => null,
        'country' => 'Norway',
        'support_email' => null,
        'phone' => null,
        'website' => null,
        'logo_path' => null,
        'logo_light_path' => null,
        'logo_dark_path' => null,
        'primary_color' => '#FF6D1F',
        'secondary_color' => '#fc7730',
        'accent_color' => '#faba98',
        'light_header_background' => '#333333',
        'light_header_color' => '#ffffff',
        'light_footer_background' => '#333333',
        'light_footer_color' => '#ffffff',
        'light_left_sidebar_background' => '#f8f9fa',
        'light_left_sidebar_color' => '#212529',
        'light_main_background' => '#d1d1d1',
        'light_right_sidebar_background' => '#f8f9fa',
        'light_right_sidebar_color' => '#212529',
        'light_page_header_background' => '#5c5c5c',
        'light_page_header_color' => '#ffffff',
        'light_card_header_background' => '#f8f9fa',
        'light_card_header_color' => '#212529',
        'light_content_background' => '#ffffff',
        'light_primary_button_background' => '#FF6D1F',
        'light_primary_button_color' => '#ffffff',
        'light_secondary_button_background' => '#fc7730',
        'light_secondary_button_color' => '#ffffff',
        'dark_header_background' => '#111827',
        'dark_header_color' => '#f8fafc',
        'dark_footer_background' => '#111827',
        'dark_footer_color' => '#f8fafc',
        'dark_left_sidebar_background' => '#1f2937',
        'dark_left_sidebar_color' => '#f8fafc',
        'dark_main_background' => '#0f172a',
        'dark_right_sidebar_background' => '#1f2937',
        'dark_right_sidebar_color' => '#f8fafc',
        'dark_page_header_background' => '#1f2937',
        'dark_page_header_color' => '#f8fafc',
        'dark_card_header_background' => '#111827',
        'dark_card_header_color' => '#f8fafc',
        'dark_content_background' => '#111827',
        'dark_primary_button_background' => '#FF6D1F',
        'dark_primary_button_color' => '#ffffff',
        'dark_secondary_button_background' => '#fc7730',
        'dark_secondary_button_color' => '#ffffff',
    ];

    /*
    |--------------------------------------------------------------------------
    | Company profile settings
    |--------------------------------------------------------------------------
    |
    | Company branding is app-wide configuration, not a separate business
    | object. The payload is stored in common_settings so beta installs can
    | adopt branding without a dedicated schema migration.
    |
    */
    public function get(): array
    {
        $payload = [];

        if ($this->settingsTableExists()) {
            $setting = CommonSetting::query()
                ->where('type', self::TYPE)
                ->where('name', self::NAME)
                ->first();

            $payload = json_decode($setting?->json ?: '[]', true) ?: [];
        }

        return $this->withComputedValues($this->normalize($payload));
    }

    public function update(
        array $data,
        ?UploadedFile $logo = null,
        ?UploadedFile $lightLogo = null,
        ?UploadedFile $darkLogo = null,
    ): array
    {
        $current = $this->get();
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        $removeLightLogo = (bool) ($data['remove_light_logo'] ?? false);
        $removeDarkLogo = (bool) ($data['remove_dark_logo'] ?? false);
        unset($data['remove_logo'], $data['remove_light_logo'], $data['remove_dark_logo']);

        $payload = $this->normalize(array_merge($current, $data));

        if ($removeLogo && filled($current['logo_path'])) {
            Storage::disk('public')->delete($current['logo_path']);
            $payload['logo_path'] = null;
        }

        if ($logo) {
            if (filled($current['logo_path'])) {
                Storage::disk('public')->delete($current['logo_path']);
            }

            $payload['logo_path'] = $logo->store('company-branding', 'public');
        }

        if ($removeLightLogo && filled($current['logo_light_path'])) {
            Storage::disk('public')->delete($current['logo_light_path']);
            $payload['logo_light_path'] = null;
        }

        if ($lightLogo) {
            if (filled($current['logo_light_path'])) {
                Storage::disk('public')->delete($current['logo_light_path']);
            }

            $payload['logo_light_path'] = $lightLogo->store('company-branding', 'public');
        }

        if ($removeDarkLogo && filled($current['logo_dark_path'])) {
            Storage::disk('public')->delete($current['logo_dark_path']);
            $payload['logo_dark_path'] = null;
        }

        if ($darkLogo) {
            if (filled($current['logo_dark_path'])) {
                Storage::disk('public')->delete($current['logo_dark_path']);
            }

            $payload['logo_dark_path'] = $darkLogo->store('company-branding', 'public');
        }

        CommonSetting::query()->updateOrCreate(
            ['type' => self::TYPE, 'name' => self::NAME],
            [
                'description' => 'Company profile and Bootstrap-compatible branding for the Nexum PSA shell.',
                'value' => $payload['company_name'],
                'json' => json_encode($payload),
            ],
        );

        return $this->withComputedValues($payload);
    }

    public function resetBranding(): array
    {
        $current = $this->get();

        collect([
            $current['logo_path'] ?? null,
            $current['logo_light_path'] ?? null,
            $current['logo_dark_path'] ?? null,
        ])
            ->filter()
            ->unique()
            ->each(fn (string $path) => Storage::disk('public')->delete($path));

        $payload = $current;

        foreach ($this->brandingKeys() as $key) {
            $payload[$key] = self::DEFAULTS[$key];
        }

        $payload = $this->normalize($payload);

        CommonSetting::query()->updateOrCreate(
            ['type' => self::TYPE, 'name' => self::NAME],
            [
                'description' => 'Company profile and Bootstrap-compatible branding for the Nexum PSA shell.',
                'value' => $payload['company_name'],
                'json' => json_encode($payload),
            ],
        );

        return $this->withComputedValues($payload);
    }

    private function normalize(array $payload): array
    {
        $payload = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $payload[$key] = trim($value) !== '' ? trim($value) : null;
            }
        }

        $payload['company_name'] = $payload['company_name'] ?: self::DEFAULTS['company_name'];
        $payload['country'] = $payload['country'] ?: self::DEFAULTS['country'];

        foreach ($this->colorKeys() as $key) {
            if (! is_string($payload[$key]) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $payload[$key])) {
                $payload[$key] = self::DEFAULTS[$key];
            }
        }

        return $payload;
    }

    private function withComputedValues(array $payload): array
    {
        $payload['logo_url'] = filled($payload['logo_path'])
            ? Storage::disk('public')->url($payload['logo_path'])
            : null;
        $payload['logo_light_url'] = filled($payload['logo_light_path'])
            ? Storage::disk('public')->url($payload['logo_light_path'])
            : $payload['logo_url'];
        $payload['logo_dark_url'] = filled($payload['logo_dark_path'])
            ? Storage::disk('public')->url($payload['logo_dark_path'])
            : $payload['logo_url'];

        return $payload;
    }

    private function colorKeys(): array
    {
        return array_values(array_filter(array_keys(self::DEFAULTS), function (string $key): bool {
            return str_ends_with($key, '_color')
                || str_ends_with($key, '_background')
                || str_ends_with($key, '_button_background');
        }));
    }

    private function brandingKeys(): array
    {
        return array_merge([
            'logo_path',
            'logo_light_path',
            'logo_dark_path',
            'primary_color',
            'secondary_color',
            'accent_color',
        ], $this->colorKeys());
    }

    private function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable('common_settings');
        } catch (Throwable) {
            return false;
        }
    }
}
