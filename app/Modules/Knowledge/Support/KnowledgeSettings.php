<?php

namespace App\Modules\Knowledge\Support;

use App\Models\Settings\CommonSetting;

class KnowledgeSettings
{
    private const TYPE = 'knowledge';

    private const NAME = 'defaults';

    public const VISIBILITY_OPTIONS = [
        'internal' => 'Internal',
        'client-wide' => 'Client-wide',
        'public' => 'Public',
    ];

    public const STATUS_OPTIONS = [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
        'needs_review' => 'Needs Review',
    ];

    private const DEFAULTS = [
        'default_visibility' => 'internal',
        'default_status' => 'published',
        'default_review_days' => 365,
        'default_priority' => 0,
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
                'description' => 'Knowledge article defaults for manually created articles.',
                'value' => $settings['default_visibility'],
                'json' => json_encode($settings),
            ],
        );

        return $settings;
    }

    public function articleDefaults(array $input = []): array
    {
        $settings = $this->get();
        $defaults = [
            'visibility' => $settings['default_visibility'],
            'status' => $settings['default_status'],
            'priority' => $settings['default_priority'],
            'next_review_at' => now()->addDays($settings['default_review_days'])->format('Y-m-d'),
        ];

        return array_merge(
            $defaults,
            array_filter($input, fn ($value): bool => $value !== null && $value !== ''),
        );
    }

    private function normalize(array $payload): array
    {
        $payload = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        $visibility = is_string($payload['default_visibility']) && array_key_exists($payload['default_visibility'], self::VISIBILITY_OPTIONS)
            ? $payload['default_visibility']
            : self::DEFAULTS['default_visibility'];

        $status = is_string($payload['default_status']) && array_key_exists($payload['default_status'], self::STATUS_OPTIONS)
            ? $payload['default_status']
            : self::DEFAULTS['default_status'];

        return [
            'default_visibility' => $visibility,
            'default_status' => $status,
            'default_review_days' => $this->intBetween($payload['default_review_days'], 1, 3650, self::DEFAULTS['default_review_days']),
            'default_priority' => $this->intBetween($payload['default_priority'], 0, 100000, self::DEFAULTS['default_priority']),
        ];
    }

    private function intBetween(mixed $value, int $min, int $max, int $fallback): int
    {
        $integer = filled($value) ? (int) $value : $fallback;

        return max($min, min($max, $integer));
    }
}
