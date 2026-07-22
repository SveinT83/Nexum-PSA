<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Cost;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends CloudFactoryModel
{
    protected $table = 'cloudfactory_offers';

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'msrp' => 'decimal:4',
            'markup_percent' => 'decimal:4',
            'manual_sale_price' => 'decimal:4',
            'sell_enabled' => 'boolean',
            'excluded' => 'boolean',
            'deprecated' => 'boolean',
            'purchasable' => 'boolean',
            'provider_payload' => 'array',
            'recurrence_term' => 'integer',
            'billing_term' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Services::class);
    }

    public function managedCost(): BelongsTo
    {
        return $this->belongsTo(Cost::class, 'cost_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'offer_id');
    }

    public function legalTerms(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Modules\Commercial\Models\Terms\terms::class,
            'cloudfactory_offer_term',
            'offer_id',
            'term_id'
        )
            ->withPivot(['is_active', 'last_seen_at'])
            ->withTimestamps();
    }

    /**
     * Human-readable provider commitment. Cloud Factory calls this RecursionTerm,
     * while Microsoft calls the same value TermDuration.
     */
    public function commitmentLabel(): ?string
    {
        return match ($this->recurrence_term) {
            null => null,
            0 => 'One-time',
            1 => 'Monthly',
            12 => 'Annual',
            36 => '3 years',
            default => $this->recurrence_term.' months',
        };
    }

    /**
     * Human-readable invoice cadence. Cloud Factory calls this BillingTerm,
     * while Microsoft calls the same value BillingCycle.
     */
    public function billingLabel(): ?string
    {
        return match ($this->billing_term) {
            null => null,
            0 => 'One-time',
            1 => 'Monthly',
            12 => 'Annual',
            36 => 'Every 3 years',
            default => 'Every '.$this->billing_term.' months',
        };
    }

    /**
     * Nexum supports one-time, monthly, quarterly, and yearly commercial periods.
     * Longer provider billing terms remain on the offer and are annualized for
     * comparable Service profitability.
     */
    public function commercialBillingInterval(): string
    {
        if ($this->billing_term === 0 || $this->recurrence_term === 0) {
            return 'one_time';
        }

        return match ((int) $this->billing_term) {
            0, 1 => 'monthly',
            3 => 'quarterly',
            default => 'yearly',
        };
    }

    public function commercialCostRecurrence(): string
    {
        return match ($this->commercialBillingInterval()) {
            'monthly' => 'month',
            'quarterly' => 'quarter',
            'yearly' => 'year',
            default => 'none',
        };
    }

    public function normalizedCost(): ?float
    {
        return $this->normalizeTermPrice($this->cost);
    }

    public function normalizedMsrp(): ?float
    {
        return $this->normalizeTermPrice($this->msrp);
    }

    private function normalizeTermPrice(mixed $price): ?float
    {
        if ($price === null) {
            return null;
        }

        $commitmentMonths = (int) $this->recurrence_term;
        if ($commitmentMonths <= 0) {
            return round((float) $price, 4);
        }

        $commercialMonths = match ($this->commercialBillingInterval()) {
            'monthly' => 1,
            'quarterly' => 3,
            'yearly' => 12,
            default => $commitmentMonths,
        };

        return round((float) $price * $commercialMonths / $commitmentMonths, 4);
    }
}
