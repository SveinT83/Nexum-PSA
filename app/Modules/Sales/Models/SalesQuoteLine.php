<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuoteLine extends Model
{
    protected $fillable = [
        'quote_version_id',
        'section',
        'sort_order',
        'source_type',
        'source_id',
        'downstream_type',
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
        'snapshot',
    ];

    protected $casts = [
        'is_optional' => 'boolean',
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
        'snapshot' => 'array',
    ];

    public function quoteVersion(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteVersion::class, 'quote_version_id');
    }
}
