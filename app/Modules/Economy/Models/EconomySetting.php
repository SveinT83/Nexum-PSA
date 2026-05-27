<?php

namespace App\Modules\Economy\Models;

use Illuminate\Database\Eloquent\Model;

class EconomySetting extends Model
{
    protected $fillable = [
        'create_orders_from_resolved_ticket_time',
        'create_orders_from_closed_ticket_time',
        'include_unresolved_ticket_time_in_period_close',
        'create_orders_from_picked_ticket_costs',
        'auto_pick_ticket_costs_on_resolved_or_closed_ticket',
        'time_order_line_grouping',
        'order_line_text_format',
        'order_prefix',
        'default_vat_rate',
    ];

    protected $casts = [
        'create_orders_from_resolved_ticket_time' => 'boolean',
        'create_orders_from_closed_ticket_time' => 'boolean',
        'include_unresolved_ticket_time_in_period_close' => 'boolean',
        'create_orders_from_picked_ticket_costs' => 'boolean',
        'auto_pick_ticket_costs_on_resolved_or_closed_ticket' => 'boolean',
        'default_vat_rate' => 'decimal:2',
    ];
}
