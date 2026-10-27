<?php

namespace App\Modules\System\Support;

use App\Models\Settings\CommonSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CompanyProfileSettings
{
    private const TYPE = 'company_profile';

    private const NAME = 'branding';

    private const THEME_PRESETS = [
        'nexum' => [
            'label' => 'Nexum (default)',
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
        ],
        'tronder-data' => [
            'label' => 'Trønder Data',
            'primary_color' => '#99B885',
            'secondary_color' => '#D7DE8C',
            'accent_color' => '#ECF5E1',
            'light_header_background' => '#0D3D35',
            'light_header_color' => '#ECF5E1',
            'light_footer_background' => '#0D3D35',
            'light_footer_color' => '#ECF5E1',
            'light_left_sidebar_background' => '#ECF5E1',
            'light_left_sidebar_color' => '#0D3D35',
            'light_main_background' => '#ECF5E1',
            'light_right_sidebar_background' => '#ECF5E1',
            'light_right_sidebar_color' => '#0D3D35',
            'light_page_header_background' => '#99B885',
            'light_page_header_color' => '#0D3D35',
            'light_card_header_background' => '#ECF5E1',
            'light_card_header_color' => '#0D3D35',
            'light_content_background' => '#FFFFFF',
            'light_primary_button_background' => '#99B885',
            'light_primary_button_color' => '#0D3D35',
            'light_secondary_button_background' => '#D7DE8C',
            'light_secondary_button_color' => '#0D3D35',
            'dark_header_background' => '#0a302a',
            'dark_header_color' => '#ECF5E1',
            'dark_footer_background' => '#0a302a',
            'dark_footer_color' => '#ECF5E1',
            'dark_left_sidebar_background' => '#145e52',
            'dark_left_sidebar_color' => '#ECF5E1',
            'dark_main_background' => '#0D3D35',
            'dark_right_sidebar_background' => '#145e52',
            'dark_right_sidebar_color' => '#ECF5E1',
            'dark_page_header_background' => '#12564a',
            'dark_page_header_color' => '#ECF5E1',
            'dark_card_header_background' => '#156759',
            'dark_card_header_color' => '#ECF5E1',
            'dark_content_background' => '#176f60',
            'dark_primary_button_background' => '#99B885',
            'dark_primary_button_color' => '#0D3D35',
            'dark_secondary_button_background' => '#D7DE8C',
            'dark_secondary_button_color' => '#0D3D35',
        ],
    ];

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
        'default_theme' => 'light',
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

    /** Every writer uses the same aggregate lock; stage files before replacing references. */
    public function update(
        array $data,
        ?UploadedFile $logo = null,
        ?UploadedFile $lightLogo = null,
        ?UploadedFile $darkLogo = null,
    ): array {
        $staged = [];
        try {
            return $this->locked(function () use ($data, $logo, $lightLogo, $darkLogo, &$staged) {
                $current = $this->get();
                $payload = $this->normalize(array_merge($current, $data));
                $slots = [
                    ['logo_path', 'remove_logo', $logo],
                    ['logo_light_path', 'remove_light_logo', $lightLogo],
                    ['logo_dark_path', 'remove_dark_logo', $darkLogo],
                ];
                foreach ($slots as [$pathKey, $removeKey, $file]) {
                    if (! empty($data[$removeKey])) {
                        $payload[$pathKey] = null;
                    }
                    if ($file) {
                        $path = $file->store('company-branding', 'public');
                        if (! is_string($path) || $path === '') {
                            throw new \RuntimeException('Company logo could not be stored.');
                        }
                        $staged[] = $path;
                        request()->attributes->set('system_staged_files', array_merge(
                            request()->attributes->get('system_staged_files', []), [$path]));
                        $payload[$pathKey] = $path;
                    }
                }

                return $this->persist($payload, $current);
            });
        } catch (Throwable $error) {
            $this->discardUnreferenced($staged);
            throw $error;
        }
    }

    public function resetBranding(): array
    {
        return $this->locked(function () {
            $current = $this->get();
            $payload = $current;
            foreach ($this->brandingKeys() as $key) {
                $payload[$key] = self::DEFAULTS[$key];
            }

            return $this->persist($this->normalize($payload), $current);
        });
    }

    public function applyPreset(string $presetKey): array
    {
        return $this->locked(function () use ($presetKey) {
            $current = $this->get();
            $preset = self::THEME_PRESETS[$presetKey] ?? null;
            if (! $preset) {
                return $current;
            }
            $payload = $current;
            foreach ($this->colorKeys() as $key) {
                if (isset($preset[$key])) {
                    $payload[$key] = $preset[$key];
                }
            }

            return $this->persist($this->normalize($payload), $current);
        });
    }

    /** Preview does not write settings/files or execute providers. */
    public function preview(array $data): array
    {
        $values = $this->normalize(array_merge($this->get(), $data));
        foreach (['remove_logo' => 'logo_path', 'remove_light_logo' => 'logo_light_path', 'remove_dark_logo' => 'logo_dark_path'] as $flag => $path) {
            if (! empty($data[$flag])) {
                $values[$path] = null;
            }
        }

        return $this->withComputedValues($values);
    }

    public static function presetValues(): array
    {
        return self::THEME_PRESETS;
    }

    /** Bind the returned fields and precondition to one committed settings snapshot. */
    public function snapshot(?array $preview = null): array
    {
        return DB::transaction(function () use ($preview) {
            if (Schema::hasTable('system_api_mutex')) {
                DB::table('system_api_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
            }
            $version = $this->version();

            return ['values' => $preview === null ? $this->get() : $this->preview($preview), 'version' => $version];
        });
    }

    public function version(): string
    {
        $revision = Schema::hasTable('system_api_mutex')
            ? (int) DB::table('system_api_mutex')->where('id', 1)->value('version') : 0;

        return '"'.SystemApiInput::hash([$revision, $this->get()]).'"';
    }

    /** Legacy installations keep working; API mutations require the additive mutex migration. */
    private function locked(callable $action): array
    {
        return DB::transaction(function () use ($action) {
            $hasMutex = Schema::hasTable('system_api_mutex');
            if ($hasMutex) {
                DB::table('system_api_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
            } else {
                CommonSetting::where('type', self::TYPE)->where('name', self::NAME)->lockForUpdate()->first();
            }
            $result = $action();
            if ($hasMutex) {
                DB::table('system_api_mutex')->where('id', 1)->increment('version');
            }

            return $result;
        });
    }

    private function persist(array $payload, array $previous): array
    {
        CommonSetting::updateOrCreate(['type' => self::TYPE, 'name' => self::NAME], [
            'description' => 'Company profile and Bootstrap-compatible branding for the Nexum PSA shell.',
            'value' => $payload['company_name'],
            'json' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $obsolete = array_values(array_diff($this->filePaths($previous), $this->filePaths($payload)));
        DB::afterCommit(fn () => $this->discardUnreferenced($obsolete));

        return $this->withComputedValues($payload);
    }

    private function filePaths(array $values): array
    {
        return array_values(array_unique(array_filter([
            $values['logo_path'] ?? null, $values['logo_light_path'] ?? null,
            $values['logo_dark_path'] ?? null,
        ])));
    }

    /** On rollback, clean only this request's staged files, never a referenced logo. */
    public function discardUnreferenced(array $paths): void
    {
        $referenced = $this->filePaths($this->get());
        foreach (array_diff($paths, $referenced) as $path) {
            if (! str_starts_with($path, 'company-branding/')) {
                continue;
            }
            try {
                if (! Storage::disk('public')->delete($path)) {
                    \Illuminate\Support\Facades\Log::warning('System branding obsolete-file cleanup requires review.');
                }
            } catch (Throwable) {
                \Illuminate\Support\Facades\Log::warning('System branding obsolete-file cleanup requires review.');
            }
        }
    }

    public static function getPresets(): array
    {
        return collect(self::THEME_PRESETS)->mapWithKeys(
            fn (array $preset, string $key) => [$key => $preset['label']],
        )->all();
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
        $payload['default_theme'] = in_array($payload['default_theme'], ['light', 'dark', 'system'], true)
            ? $payload['default_theme']
            : self::DEFAULTS['default_theme'];

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
            'default_theme',
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
