<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuoteLine extends Model
{
    protected $fillable = [
        'quote_version_id',
        'option_group_id',
        'section',
        'sort_order',
        'source_type',
        'source_id',
        'downstream_type',
        'billing_cadence',
        'is_optional',
        'sku',
        'name',
        'description',
        'quantity',
        'unit',
        'unit_cost_ex_vat',
        'unit_price_ex_vat',
        'discount_value',
        'discount_type',
        'vat_rate',
        'line_total_ex_vat',
        'vat_amount',
        'line_total_inc_vat',
        'margin_amount',
        'margin_percent',
        'is_required',
        'is_recommended',
        'customer_selected_by_default',
        'customer_quantity_editable',
        'min_customer_quantity',
        'max_customer_quantity',
        'customer_label',
        'snapshot',
    ];

    protected $casts = [
        'is_optional' => 'boolean',
        'is_required' => 'boolean',
        'is_recommended' => 'boolean',
        'customer_selected_by_default' => 'boolean',
        'customer_quantity_editable' => 'boolean',
        'quantity' => 'decimal:2',
        'unit_cost_ex_vat' => 'decimal:2',
        'unit_price_ex_vat' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'line_total_ex_vat' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'line_total_inc_vat' => 'decimal:2',
        'margin_amount' => 'decimal:2',
        'margin_percent' => 'decimal:2',
        'min_customer_quantity' => 'decimal:2',
        'max_customer_quantity' => 'decimal:2',
        'snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (SalesQuoteLine $line): void {
            if (! filled($line->billing_cadence)) {
                $line->billing_cadence = $line->downstream_type === 'recurring_contract'
                    || $line->section === 'monthly_services'
                    ? 'monthly'
                    : 'one_time';
            }

            if ($line->is_required === null) {
                $line->is_required = ! (bool) $line->is_optional;
            }

            if ($line->customer_selected_by_default === null) {
                $line->customer_selected_by_default = true;
            }

            if ($line->min_customer_quantity === null) {
                $line->min_customer_quantity = $line->quantity;
            }

            if ($line->max_customer_quantity === null) {
                $line->max_customer_quantity = $line->quantity;
            }
        });
    }

    public function quoteVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'quote_version_id');
    }

    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteOptionGroup::class, 'option_group_id');
    }
}
