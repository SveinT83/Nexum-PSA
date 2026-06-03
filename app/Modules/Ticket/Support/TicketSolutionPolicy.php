<?php

namespace App\Modules\Ticket\Support;

use App\Models\Settings\CommonSetting;
use Illuminate\Support\Arr;

class TicketSolutionPolicy
{
    public const SETTING_TYPE = 'ticket';
    public const SETTING_NAME = 'solution_policy';

    public function settings(): array
    {
        $json = CommonSetting::query()
            ->where('type', self::SETTING_TYPE)
            ->where('name', self::SETTING_NAME)
            ->value('json');

        $payload = json_decode((string) $json, true);

        return [
            'allow_internal_solution_notes' => (bool) Arr::get(
                is_array($payload) ? $payload : [],
                'allow_internal_solution_notes',
                true
            ),
        ];
    }

    public function allowsInternalSolutionNotes(): bool
    {
        return $this->settings()['allow_internal_solution_notes'];
    }

    public function update(array $settings): void
    {
        CommonSetting::updateOrCreate(
            ['type' => self::SETTING_TYPE, 'name' => self::SETTING_NAME],
            [
                'description' => 'Ticket solution policy.',
                'json' => json_encode([
                    'allow_internal_solution_notes' => (bool) ($settings['allow_internal_solution_notes'] ?? false),
                ]),
            ]
        );
    }
}
