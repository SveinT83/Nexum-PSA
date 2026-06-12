<?php

namespace App\Modules\Commercial\Support;

use App\Models\Settings\CommonSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ClientTimebankQuickPolicy
{
    private const TYPE = 'commercial';

    private const NAME = 'client_timebank_quick_policy';

    private const DEFAULTS = [
        'quick_timebank_enabled' => true,
        'quick_timebank_require_remaining' => true,
        'quick_timebank_allow_overuse' => false,
        'quick_timebank_require_note' => true,
        'quick_timebank_max_minutes' => 120,
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

    public function update(array $data): array
    {
        $payload = $this->normalize($data);

        CommonSetting::query()->updateOrCreate(
            ['type' => self::TYPE, 'name' => self::NAME],
            [
                'description' => 'Commercial policy for Client profile quick contract timebank registration.',
                'value' => $payload['quick_timebank_enabled'] ? '1' : '0',
                'json' => json_encode($payload),
            ],
        );

        return $payload;
    }

    private function normalize(array $payload): array
    {
        $payload = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        foreach ([
            'quick_timebank_enabled',
            'quick_timebank_require_remaining',
            'quick_timebank_allow_overuse',
            'quick_timebank_require_note',
        ] as $key) {
            $payload[$key] = filter_var($payload[$key], FILTER_VALIDATE_BOOLEAN);
        }

        $payload['quick_timebank_max_minutes'] = max(1, (int) $payload['quick_timebank_max_minutes']);

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
