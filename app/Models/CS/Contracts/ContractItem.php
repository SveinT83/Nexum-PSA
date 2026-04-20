<?php

namespace App\Models\CS\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractItem extends Model
{
    protected $table = 'contract_items';

    protected $fillable = [
        'contract_id',
        'service_id',
        'name',
        'sku',
        'unit_price',
        'quantity',
        'unit',
        'billing_interval',
        'discount_value',
        'discount_type',
        'setup_fee',
        'sla',
        'caps',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'unit' => 'string',
        'discount_value' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'caps' => 'integer',
    ];

    // -----------------------------
    // Relationships
    // -----------------------------

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contracts::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(\App\Models\CS\Services\Services::class);
    }

    // -----------------------------
    // Helpers (optional)
    // -----------------------------

    public function getLineTotalAttribute(): float
    {
        $base = (float) $this->unit_price * (int) $this->quantity;

        if ($this->discount_value && $this->discount_type === 'percent') {
            return $base * (1 - ($this->discount_value / 100));
        }

        if ($this->discount_value && $this->discount_type === 'amount') {
            return max(0, $base - $this->discount_value);
        }

        return $base;
    }
}
