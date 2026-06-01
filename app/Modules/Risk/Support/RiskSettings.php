<?php

namespace App\Modules\Risk\Support;

use App\Models\Settings\CommonSetting;

class RiskSettings
{
    private const TYPE = 'risk';

    private const NAME = 'defaults';

    public const ASSESSMENT_SCOPE_OPTIONS = [
        'internal' => 'Internal',
        'client' => 'Client specific',
    ];

    public const ASSESSMENT_STATUS_OPTIONS = [
        'new' => 'New',
        'open' => 'Open',
        'in_progress' => 'In Progress',
    ];

    public const ITEM_STATUS_OPTIONS = [
        'open' => 'Open',
        'mitigated' => 'Mitigated',
        'accepted' => 'Accepted',
    ];

    private const DEFAULTS = [
        'default_assessment_scope' => 'internal',
        'default_assessment_status' => 'new',
        'default_item_likelihood' => 3,
        'default_item_impact' => 3,
        'default_item_status' => 'open',
        'default_item_review_days' => 90,
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
                'description' => 'Risk module defaults for assessments and risk items.',
                'value' => $settings['default_assessment_scope'],
                'json' => json_encode($settings),
            ],
        );

        return $settings;
    }

    public function assessmentDefaults(array $input = []): array
    {
        $settings = $this->get();

        return array_merge([
            'scope' => $settings['default_assessment_scope'],
            'status' => $settings['default_assessment_status'],
        ], array_filter($input, fn ($value): bool => $value !== null && $value !== ''));
    }

    public function itemDefaults(array $input = []): array
    {
        $settings = $this->get();

        return array_merge([
            'likelihood' => $settings['default_item_likelihood'],
            'impact' => $settings['default_item_impact'],
            'status' => $settings['default_item_status'],
            'next_review_at' => now()->addDays($settings['default_item_review_days'])->format('Y-m-d'),
        ], array_filter($input, fn ($value): bool => $value !== null && $value !== ''));
    }

    private function normalize(array $payload): array
    {
        $payload = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        $scope = is_string($payload['default_assessment_scope']) && array_key_exists($payload['default_assessment_scope'], self::ASSESSMENT_SCOPE_OPTIONS)
            ? $payload['default_assessment_scope']
            : self::DEFAULTS['default_assessment_scope'];

        $assessmentStatus = is_string($payload['default_assessment_status']) && array_key_exists($payload['default_assessment_status'], self::ASSESSMENT_STATUS_OPTIONS)
            ? $payload['default_assessment_status']
            : self::DEFAULTS['default_assessment_status'];

        $itemStatus = is_string($payload['default_item_status']) && array_key_exists($payload['default_item_status'], self::ITEM_STATUS_OPTIONS)
            ? $payload['default_item_status']
            : self::DEFAULTS['default_item_status'];

        return [
            'default_assessment_scope' => $scope,
            'default_assessment_status' => $assessmentStatus,
            'default_item_likelihood' => $this->intBetween($payload['default_item_likelihood'], 1, 5, self::DEFAULTS['default_item_likelihood']),
            'default_item_impact' => $this->intBetween($payload['default_item_impact'], 1, 5, self::DEFAULTS['default_item_impact']),
            'default_item_status' => $itemStatus,
            'default_item_review_days' => $this->intBetween($payload['default_item_review_days'], 1, 3650, self::DEFAULTS['default_item_review_days']),
        ];
    }

    private function intBetween(mixed $value, int $min, int $max, int $fallback): int
    {
        $integer = filled($value) ? (int) $value : $fallback;

        return max($min, min($max, $integer));
    }
}
