<?php

namespace App\Modules\Warroom\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Both browser and API accept exactly the same global settings fields and ranges. */
class WarroomSettingsInput
{
    public static function validate(array $payload): array
    {
        $rules = [
            'due_soon_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'inbox_recent_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'latest_tickets_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'latest_alerts_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'calendar_today_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'recent_integrations_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'enabled_sections' => ['required', 'array', 'list', 'min:1', 'max:9'],
            'enabled_sections.*' => ['required', 'string', 'distinct', Rule::in(array_keys(WarroomSettings::SECTION_OPTIONS))],
        ];
        abort_if(array_diff(array_keys($payload), array_keys(array_filter($rules, fn ($key) => ! str_contains($key, '*'), ARRAY_FILTER_USE_KEY))) !== [],
            422, 'Unsupported Warroom settings fields.');

        return Validator::make($payload, $rules)->validate();
    }
}
