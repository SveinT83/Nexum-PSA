<?php

namespace App\Modules\Commercial\Models\Contracts;

use App\Modules\Commercial\Actions\SyncContractPricingSummary;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Support\ContractPricing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class ContractItem extends Model
{
    protected $table = 'contract_items';

    protected $fillable = [
        'contract_id',
        'service_id',
        'source',
        'provider_subscription_id',
        'cloudfactory_offer_id',
        'commitment_start_date',
        'commitment_end_date',
        'cancellation_deadline',
        'billing_effective_at',
        'licence_metadata',
        'name',
        'sku',
        'customer_description',
        'customer_unit_singular',
        'customer_unit_plural',
        'unit_price',
        'price_currency',
        'cost_unit_price',
        'cost_currency',
        'quantity',
        'unit',
        'billing_interval',
        'discount_value',
        'discount_type',
        'setup_fee',
        'sla_id',
        'uses_contract_default_sla',
        'sla_snapshot',
        'sla',
        'caps',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'price_currency' => 'string',
        'cost_unit_price' => 'decimal:4',
        'quantity' => 'integer',
        'unit' => 'string',
        'discount_value' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'uses_contract_default_sla' => 'boolean',
        'sla_snapshot' => 'array',
        'caps' => 'integer',
        'commitment_start_date' => 'date',
        'commitment_end_date' => 'date',
        'cancellation_deadline' => 'date',
        'billing_effective_at' => 'datetime',
        'licence_metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (ContractItem $item): void {
            $currency = strtoupper(trim((string) ($item->price_currency ?: 'NOK')));

            if ($currency !== 'NOK') {
                throw new InvalidArgumentException(
                    'Contract sale currency must be NOK. Cost currency is stored separately.'
                );
            }

            $item->price_currency = $currency;
        });

        static::saved(function (ContractItem $item): void {
            app(SyncContractPricingSummary::class)->handle((int) $item->contract_id);
        });

        static::deleted(function (ContractItem $item): void {
            app(SyncContractPricingSummary::class)->handle((int) $item->contract_id);
        });
    }

    // -----------------------------
    // Relationships
    // -----------------------------

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contracts::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Commercial\Models\Services\Services::class);
    }

    public function cloudFactoryOffer(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Integration\Models\CloudFactory\Offer::class, 'cloudfactory_offer_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(Sla::class, 'sla_id');
    }

    public function timeRates()
    {
        return $this->hasMany(ContractItemTimeRate::class, 'contract_item_id')->orderBy('sort_order')->orderBy('name');
    }

    public function cloudFactorySubscription()
    {
        return $this->hasOne(\App\Modules\Integration\Models\CloudFactory\Subscription::class, 'contract_item_id');
    }

    // -----------------------------
    // Helpers (optional)
    // -----------------------------

    public function getLineTotalAttribute(): string
    {
        return app(ContractPricing::class)
            ->calculateLine($this)['line_total']['decimal'];
    }
}
