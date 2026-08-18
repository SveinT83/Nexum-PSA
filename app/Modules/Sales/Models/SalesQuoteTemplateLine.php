<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuoteTemplateLine extends Model
{
    protected $fillable = [
        'template_id',
        'template_option_group_id',
        'section',
        'sort_order',
        'source_type',
        'source_id',
        'downstream_type',
        'billing_cadence',
        'is_required',
        'is_recommended',
        'customer_selected_by_default',
        'customer_quantity_editable',
        'min_customer_quantity',
        'max_customer_quantity',
        'customer_label',
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
        'source_snapshot',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_recommended' => 'boolean',
        'customer_selected_by_default' => 'boolean',
        'customer_quantity_editable' => 'boolean',
        'quantity' => 'decimal:2',
        'unit_cost_ex_vat' => 'decimal:2',
        'unit_price_ex_vat' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'min_customer_quantity' => 'decimal:2',
        'max_customer_quantity' => 'decimal:2',
        'source_snapshot' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteTemplate::class, 'template_id');
    }

    public function optionGroup(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteTemplateOptionGroup::class, 'template_option_group_id');
    }

    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'option_group_id' => $this->template_option_group_id,
            'section' => $this->section,
            'sort_order' => $this->sort_order,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'downstream_type' => $this->downstream_type,
            'billing_cadence' => $this->billing_cadence,
            'is_required' => $this->is_required,
            'is_recommended' => $this->is_recommended,
            'customer_selected_by_default' => $this->customer_selected_by_default,
            'customer_quantity_editable' => $this->customer_quantity_editable,
            'min_customer_quantity' => $this->min_customer_quantity,
            'max_customer_quantity' => $this->max_customer_quantity,
            'customer_label' => $this->customer_label,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'unit_cost_ex_vat' => $this->unit_cost_ex_vat,
            'unit_price_ex_vat' => $this->unit_price_ex_vat,
            'discount_value' => $this->discount_value,
            'discount_type' => $this->discount_type,
            'vat_rate' => $this->vat_rate,
            'source_snapshot' => $this->source_snapshot ?: [],
        ];
    }
}
