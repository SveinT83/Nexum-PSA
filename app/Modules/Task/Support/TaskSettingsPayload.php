<?php

namespace App\Modules\Task\Support;

use Illuminate\Validation\Rule;

/** Browser and API settings updates share the same validation contract. */
class TaskSettingsPayload
{
    public static function rules(): array
    {
        return [
            'default_status_id' => ['required', 'integer', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'default_priority_id' => ['nullable', 'integer', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'default_estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'ticket_billing_minutes_mode' => ['required', Rule::in(TaskSettings::TICKET_BILLING_MODES)],
        ];
    }
}
