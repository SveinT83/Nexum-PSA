<?php

namespace App\Modules\Marketing\Support;

use App\Models\Settings\CommonSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MarketingSettings
{
    private const TYPE = 'marketing';

    private const NAME = 'settings';

    public const CONSENT_MODE_OPTIONS = [
        'opt_out' => 'Opt-out by default',
        'explicit_opt_in' => 'Explicit opt-in required',
    ];

    public const UNSUBSCRIBE_MODE_OPTIONS = [
        'all_marketing' => 'Unsubscribe from all marketing',
        'category' => 'Unsubscribe from category',
    ];

    private const DEFAULTS = [
        'consent_mode' => 'opt_out',
        'unsubscribe_mode' => 'all_marketing',
        'active_contract_clients_eligible' => true,
        'open_tracking_enabled' => true,
        'click_tracking_enabled' => true,
        'default_batch_size' => 50,
        'default_send_interval_minutes' => 15,
        'quiet_hours_start' => null,
        'quiet_hours_end' => null,
        'unsubscribe_footer' => 'You receive this email because your organization has a business relationship with us. You can unsubscribe at any time.',
    ];

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

        return $this->normalize($payload);
    }

    public function update(array $payload): array
    {
        $settings = $this->normalize($payload);

        CommonSetting::query()->updateOrCreate(
            ['type' => self::TYPE, 'name' => self::NAME],
            [
                'description' => 'Marketing consent, unsubscribe, tracking, and send batching settings.',
                'value' => $settings['consent_mode'],
                'json' => json_encode($settings),
            ],
        );

        return $settings;
    }

    private function normalize(array $payload): array
    {
        $payload = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));
        $payload['consent_mode'] = in_array($payload['consent_mode'], array_keys(self::CONSENT_MODE_OPTIONS), true)
            ? $payload['consent_mode']
            : self::DEFAULTS['consent_mode'];
        $payload['unsubscribe_mode'] = in_array($payload['unsubscribe_mode'], array_keys(self::UNSUBSCRIBE_MODE_OPTIONS), true)
            ? $payload['unsubscribe_mode']
            : self::DEFAULTS['unsubscribe_mode'];
        $payload['default_batch_size'] = max(1, (int) $payload['default_batch_size']);
        $payload['default_send_interval_minutes'] = max(1, (int) $payload['default_send_interval_minutes']);
        $payload['quiet_hours_start'] = $this->normalizeTime($payload['quiet_hours_start']);
        $payload['quiet_hours_end'] = $this->normalizeTime($payload['quiet_hours_end']);
        $payload['unsubscribe_footer'] = trim((string) $payload['unsubscribe_footer']) ?: self::DEFAULTS['unsubscribe_footer'];

        foreach (['active_contract_clients_eligible', 'open_tracking_enabled', 'click_tracking_enabled'] as $key) {
            $payload[$key] = (bool) $payload[$key];
        }

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

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{2}:\d{2}$/', $value)) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $value));

        return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59
            ? sprintf('%02d:%02d', $hour, $minute)
            : null;
    }
}
