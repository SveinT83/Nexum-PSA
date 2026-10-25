<?php

namespace App\Modules\Economy\Actions;

use App\Modules\Economy\Models\EconomySetting;
use App\Modules\Economy\Support\EconomyMutex;

/** PATCH preserves omitted values; the browser adapter explicitly supplies unchecked booleans. */
class UpdateEconomySettings
{
    public const BOOLEANS = [
        'create_orders_from_resolved_ticket_time', 'create_orders_from_closed_ticket_time',
        'include_unresolved_ticket_time_in_period_close', 'create_orders_from_picked_ticket_costs',
        'auto_pick_ticket_costs_on_resolved_or_closed_ticket',
    ];

    public static function rules(bool $partial = true): array
    {
        $rules = array_fill_keys(self::BOOLEANS, [$partial ? 'sometimes' : 'nullable', 'boolean']);
        $required = $partial ? 'sometimes' : 'required';

        return $rules + [
            'time_order_line_grouping' => [$required, 'required', 'in:per_entry'],
            'order_line_text_format' => [$required, 'required', 'in:ticket_date_text'],
            'order_prefix' => [$required, 'required', 'string', 'max:20'],
            'default_vat_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function handle(array $data): EconomySetting
    {
        return app(EconomyMutex::class)->run(function () use ($data) {
            $settings = app(EnsureEconomyDefaults::class)->handle();
            $settings->update(validator($data, self::rules())->validate());

            return $settings->refresh();
        });
    }
}
