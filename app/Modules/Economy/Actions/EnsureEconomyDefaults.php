<?php

namespace App\Modules\Economy\Actions;

use App\Models\Settings\CommonSetting;
use App\Modules\Economy\Models\EconomySetting;

class EnsureEconomyDefaults
{
    /*
    |--------------------------------------------------------------------------
    | Economy defaults
    |--------------------------------------------------------------------------
    |
    | Economy owns order generation settings. VAT may already be configured in
    | the older common economy settings surface, so the default row mirrors that
    | value without making it mandatory for orders that have no VAT.
    |
    */
    public function handle(): EconomySetting
    {
        $settings = EconomySetting::query()->first();

        if ($settings) {
            if ($settings->default_vat_rate === null) {
                $settings->forceFill([
                    'default_vat_rate' => $this->defaultVatRate(),
                ])->save();
            }

            return $settings;
        }

        return EconomySetting::create([
            'create_orders_from_resolved_ticket_time' => false,
            'create_orders_from_closed_ticket_time' => true,
            'include_unresolved_ticket_time_in_period_close' => false,
            'create_orders_from_picked_ticket_costs' => true,
            'auto_pick_ticket_costs_on_resolved_or_closed_ticket' => false,
            'time_order_line_grouping' => 'per_entry',
            'order_line_text_format' => 'ticket_date_text',
            'order_prefix' => 'ORD-',
            'default_vat_rate' => $this->defaultVatRate(),
        ]);
    }

    private function defaultVatRate(): float
    {
        $vat = CommonSetting::query()
            ->where('type', 'economy')
            ->where('name', 'vat')
            ->value('value');

        return is_numeric($vat) ? (float) $vat : 25.0;
    }
}
