<?php

namespace App\Modules\Ticket\Support;

use App\Models\Settings\CommonSetting;
use Illuminate\Support\Arr;

class TicketPortalPolicy
{
    public const SETTING_TYPE = 'ticket';
    public const SETTING_NAME = 'portal_policy';

    public const VISIBILITY_UNPUBLISHED = 'unpublished';
    public const VISIBILITY_PUBLISHED = 'published';

    /**
     * @return array{default_customer_visibility: string}
     */
    public function settings(): array
    {
        $json = CommonSetting::query()
            ->where('type', self::SETTING_TYPE)
            ->where('name', self::SETTING_NAME)
            ->value('json');

        $payload = json_decode((string) $json, true);
        $visibility = Arr::get(is_array($payload) ? $payload : [], 'default_customer_visibility', self::VISIBILITY_UNPUBLISHED);

        if (! array_key_exists($visibility, self::visibilityOptions())) {
            $visibility = self::VISIBILITY_UNPUBLISHED;
        }

        return [
            'default_customer_visibility' => $visibility,
        ];
    }

    public function defaultCustomerVisibility(): string
    {
        return $this->settings()['default_customer_visibility'];
    }

    public function update(array $settings): void
    {
        $visibility = $settings['default_customer_visibility'] ?? self::VISIBILITY_UNPUBLISHED;

        if (! array_key_exists($visibility, self::visibilityOptions())) {
            $visibility = self::VISIBILITY_UNPUBLISHED;
        }

        CommonSetting::updateOrCreate(
            ['type' => self::SETTING_TYPE, 'name' => self::SETTING_NAME],
            [
                'description' => 'Ticket customer portal publishing policy.',
                'json' => json_encode([
                    'default_customer_visibility' => $visibility,
                ]),
            ]
        );
    }

    /**
     * @return array<string, string>
     */
    public static function visibilityOptions(): array
    {
        return [
            self::VISIBILITY_UNPUBLISHED => 'Unpublished',
            self::VISIBILITY_PUBLISHED => 'Published',
        ];
    }
}
