<?php

namespace App\Modules\Warroom\Support;

use App\Models\Settings\CommonSetting;

class WarroomSettings
{
    private const TYPE = 'warroom';

    private const NAME = 'dashboard';

    public const SECTION_OPTIONS = [
        'pulse' => 'Pulse metrics',
        'tickets' => 'Ticket fireline',
        'asset_alerts' => 'Asset alerts',
        'calendar' => 'Calendar',
        'domain_radar' => 'Domain radar',
        'integrations' => 'Integration health',
        'lanes' => 'Warroom lanes',
        'system_health' => 'System health',
        'next_actions' => 'Next actions',
    ];

    private const DEFAULTS = [
        'due_soon_hours' => 8,
        'inbox_recent_hours' => 24,
        'latest_tickets_limit' => 6,
        'latest_alerts_limit' => 5,
        'calendar_today_limit' => 5,
        'recent_integrations_limit' => 5,
        'enabled_sections' => [
            'pulse',
            'tickets',
            'asset_alerts',
            'calendar',
            'domain_radar',
            'integrations',
            'lanes',
            'system_health',
            'next_actions',
        ],
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
                'description' => 'Warroom dashboard visibility, limits, and operational time windows.',
                'value' => (string) $settings['due_soon_hours'],
                'json' => json_encode($settings),
            ],
        );

        return $settings;
    }

    public function sectionEnabled(array $settings, string $section): bool
    {
        return in_array($section, $settings['enabled_sections'] ?? [], true);
    }

    private function normalize(array $payload): array
    {
        $payload = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        $enabledSections = array_values(array_intersect(
            array_map('strval', (array) $payload['enabled_sections']),
            array_keys(self::SECTION_OPTIONS),
        ));

        if ($enabledSections === []) {
            $enabledSections = self::DEFAULTS['enabled_sections'];
        }

        return [
            'due_soon_hours' => $this->intBetween($payload['due_soon_hours'], 1, 168, self::DEFAULTS['due_soon_hours']),
            'inbox_recent_hours' => $this->intBetween($payload['inbox_recent_hours'], 1, 168, self::DEFAULTS['inbox_recent_hours']),
            'latest_tickets_limit' => $this->intBetween($payload['latest_tickets_limit'], 1, 20, self::DEFAULTS['latest_tickets_limit']),
            'latest_alerts_limit' => $this->intBetween($payload['latest_alerts_limit'], 1, 20, self::DEFAULTS['latest_alerts_limit']),
            'calendar_today_limit' => $this->intBetween($payload['calendar_today_limit'], 1, 20, self::DEFAULTS['calendar_today_limit']),
            'recent_integrations_limit' => $this->intBetween($payload['recent_integrations_limit'], 1, 20, self::DEFAULTS['recent_integrations_limit']),
            'enabled_sections' => $enabledSections,
        ];
    }

    private function intBetween(mixed $value, int $min, int $max, int $fallback): int
    {
        $integer = filled($value) ? (int) $value : $fallback;

        return max($min, min($max, $integer));
    }
}
