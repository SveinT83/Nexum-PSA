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
        'primary_color' => '#0d6efd',
        'secondary_color' => '#6c757d',
        'accent_color' => '#20c997',
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

    public function update(array $data, ?UploadedFile $logo = null): array
    {
        $current = $this->get();
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        unset($data['remove_logo']);

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

        foreach (['primary_color', 'secondary_color', 'accent_color'] as $key) {
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

        return $payload;
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
