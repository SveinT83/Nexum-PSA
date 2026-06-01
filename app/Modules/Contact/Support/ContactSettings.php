<?php

namespace App\Modules\Contact\Support;

use App\Models\Settings\CommonSetting;
use Illuminate\Support\Str;

class ContactSettings
{
    private const TYPE = 'contact';

    private const NAME = 'defaults';

    public const CONTACT_TYPE_OPTIONS = [
        'person' => 'Person',
        'department' => 'Department',
        'team' => 'Team',
        'shared_mailbox' => 'Shared Mailbox',
        'vendor_queue' => 'Vendor Queue',
        'other' => 'Other',
    ];

    public const STATUS_OPTIONS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'archived' => 'Archived',
    ];

    public const RELATION_OPTIONS = [
        'contact' => 'Contact',
        'primary_contact' => 'Primary contact',
        'technical_contact' => 'Technical contact',
        'billing_contact' => 'Billing contact',
        'site_contact' => 'Site contact',
        'decision_maker' => 'Decision maker',
        'emergency_contact' => 'Emergency contact',
        'manager' => 'Manager',
        'ceo' => 'CEO',
    ];

    private const DEFAULTS = [
        'enabled_relation_types' => [
            'contact',
            'primary_contact',
            'technical_contact',
            'billing_contact',
            'site_contact',
            'decision_maker',
            'emergency_contact',
            'manager',
            'ceo',
        ],
        'default_relation_type' => 'contact',
        'default_contact_type' => 'person',
        'default_status' => 'active',
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
                'description' => 'Contact module defaults and relation type choices.',
                'value' => $settings['default_relation_type'],
                'json' => json_encode($settings),
            ],
        );

        return $settings;
    }

    public function relationOptions(?string $include = null): array
    {
        $enabled = array_flip($this->enabledRelationValues($include));

        return array_filter(
            self::RELATION_OPTIONS,
            fn (string $key): bool => array_key_exists($key, $enabled),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function enabledRelationValues(?string $include = null): array
    {
        $settings = $this->get();
        $values = $settings['enabled_relation_types'];

        if ($include && ! in_array($include, $values, true)) {
            $values[] = $include;
        }

        return $values;
    }

    public function relationLabel(string $value): string
    {
        return self::RELATION_OPTIONS[$value] ?? Str::headline(str_replace('_', ' ', $value));
    }

    private function normalize(array $payload): array
    {
        $payload = array_merge(self::DEFAULTS, array_intersect_key($payload, self::DEFAULTS));

        $enabledRelations = array_values(array_intersect(
            array_map('strval', (array) $payload['enabled_relation_types']),
            array_keys(self::RELATION_OPTIONS),
        ));

        if ($enabledRelations === []) {
            $enabledRelations = self::DEFAULTS['enabled_relation_types'];
        }

        $defaultRelation = is_string($payload['default_relation_type']) ? $payload['default_relation_type'] : self::DEFAULTS['default_relation_type'];
        if (! in_array($defaultRelation, $enabledRelations, true)) {
            $defaultRelation = in_array(self::DEFAULTS['default_relation_type'], $enabledRelations, true)
                ? self::DEFAULTS['default_relation_type']
                : $enabledRelations[0];
        }

        $defaultType = is_string($payload['default_contact_type']) && array_key_exists($payload['default_contact_type'], self::CONTACT_TYPE_OPTIONS)
            ? $payload['default_contact_type']
            : self::DEFAULTS['default_contact_type'];

        $defaultStatus = is_string($payload['default_status']) && array_key_exists($payload['default_status'], self::STATUS_OPTIONS)
            ? $payload['default_status']
            : self::DEFAULTS['default_status'];

        return [
            'enabled_relation_types' => $enabledRelations,
            'default_relation_type' => $defaultRelation,
            'default_contact_type' => $defaultType,
            'default_status' => $defaultStatus,
        ];
    }
}
