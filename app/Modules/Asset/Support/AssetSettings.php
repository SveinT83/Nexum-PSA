<?php

namespace App\Modules\Asset\Support;

use App\Models\Settings\CommonSetting;

/**
 * Central access point for Asset module settings stored in common_settings.
 *
 * The Asset type column is currently an enum, so settings may enable/disable
 * existing system types but must not invent new type values until the schema is
 * changed deliberately.
 */
class AssetSettings
{
    private const TYPE = 'asset';

    private const NAME = 'defaults';

    public const TYPE_OPTIONS = [
        'server' => 'Server',
        'pc' => 'PC',
        'laptop' => 'Laptop',
        'switch' => 'Switch',
        'ap' => 'Access Point',
        'firewall' => 'Firewall',
        'mobile' => 'Mobile',
        'other' => 'Other',
    ];

    public const STATUS_OPTIONS = [
        'unknown' => 'Unknown',
        'online' => 'Online',
        'offline' => 'Offline',
        'warning' => 'Warning',
        'in_service' => 'In Service',
    ];

    public const IP_TYPE_OPTIONS = [
        'dhcp' => 'DHCP',
        'fixed' => 'Fixed',
    ];

    private const DEFAULTS = [
        'enabled_types' => ['server', 'pc', 'laptop', 'switch', 'ap', 'firewall', 'mobile', 'other'],
        'default_type' => 'other',
        'default_ip_type' => 'dhcp',
        'default_status' => 'unknown',
    ];

    public function get(): array
    {
        $setting = CommonSetting::query()
            ->where('type', self::TYPE)
            ->where('name', self::NAME)
            ->first();

        return $this->normalize(json_decode($setting?->json ?: '[]', true) ?: []);
    }

    public function update(array $payload): array
    {
        $settings = $this->normalize($payload);

        CommonSetting::query()->updateOrCreate(
            ['type' => self::TYPE, 'name' => self::NAME],
            [
                'description' => 'Asset module defaults for manual asset registration.',
                'value' => $settings['default_type'],
                'json' => json_encode($settings),
            ],
        );

        return $settings;
    }

    public function typeOptions(?string $include = null): array
    {
        $enabled = array_flip($this->enabledTypeValues($include));

        return array_filter(
            self::TYPE_OPTIONS,
            fn (string $value): bool => array_key_exists($value, $enabled),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function statusOptions(?string $include = null): array
    {
        $options = self::STATUS_OPTIONS;

        if ($include && ! array_key_exists($include, $options)) {
            $options[$include] = ucfirst(str_replace('_', ' ', $include));
        }

        return $options;
    }

    public function ipTypeOptions(): array
    {
        return self::IP_TYPE_OPTIONS;
    }

    public function enabledTypeValues(?string $include = null): array
    {
        $settings = $this->get();
        $values = $settings['enabled_types'];

        if ($include && array_key_exists($include, self::TYPE_OPTIONS) && ! in_array($include, $values, true)) {
            $values[] = $include;
        }

        return $values;
    }

    public function statusValues(?string $include = null): array
    {
        return array_keys($this->statusOptions($include));
    }

    public function manualCreateDefaults(array $input = []): array
    {
        $settings = $this->get();

        return array_merge([
            'type' => $settings['default_type'],
            'ip_type' => $settings['default_ip_type'],
            'status' => $settings['default_status'],
        ], array_filter($input, fn ($value): bool => $value !== null && $value !== ''));
    }

    private function normalize(array $payload): array
    {
        $payload = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        $enabledTypes = array_values(array_intersect(
            array_map('strval', (array) $payload['enabled_types']),
            array_keys(self::TYPE_OPTIONS),
        ));

        if ($enabledTypes === []) {
            $enabledTypes = self::DEFAULTS['enabled_types'];
        }

        $defaultType = is_string($payload['default_type']) ? $payload['default_type'] : self::DEFAULTS['default_type'];
        if (! in_array($defaultType, $enabledTypes, true)) {
            $defaultType = in_array(self::DEFAULTS['default_type'], $enabledTypes, true)
                ? self::DEFAULTS['default_type']
                : $enabledTypes[0];
        }

        $defaultIpType = is_string($payload['default_ip_type']) && array_key_exists($payload['default_ip_type'], self::IP_TYPE_OPTIONS)
            ? $payload['default_ip_type']
            : self::DEFAULTS['default_ip_type'];

        $defaultStatus = is_string($payload['default_status']) && array_key_exists($payload['default_status'], self::STATUS_OPTIONS)
            ? $payload['default_status']
            : self::DEFAULTS['default_status'];

        return [
            'enabled_types' => $enabledTypes,
            'default_type' => $defaultType,
            'default_ip_type' => $defaultIpType,
            'default_status' => $defaultStatus,
        ];
    }
}
