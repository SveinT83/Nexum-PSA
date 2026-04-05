<?php

namespace App\Models\CS\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contracts extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_id',
        'description',
        'start_date',
        'end_date',
        'binding_end_date',
        'auto_renew',
        'renewal_months',
        'allow_indexing_during_binding',
        'allow_decrease_during_binding',
        'max_index_pct_binding',
        'post_binding_index_pct',
        'approval_status',
        'created_by',
        'terms_snapshot',
        'dpa_snapshot',
        'legal_snapshot',
        'sla_snapshot',
        'general_snapshot',
        'secure_token',
        'sent_at',
        'accepted_at',
        'accepted_by_name',
        'accepted_ip',
        'accepted_ua',
        'viewed_at',
        'viewed_ip',
        'viewed_ua',
        'cc_email',
    ];

    protected function casts(): array
    {
        return [
            'approval_status' => 'string',
            'approval_approved_at' => 'datetime',

            'start_date' => 'date',
            'end_date' => 'date',
            'binding_end_date' => 'date',

            'auto_renew' => 'boolean',

            'allow_indexing_during_binding' => 'boolean',
            'allow_decrease_during_binding' => 'boolean',

            'max_index_pct_binding' => 'decimal:2',
            'post_binding_index_pct' => 'decimal:2',

            'total_monthly_amount' => 'decimal:2',

            'last_indexed_at' => 'datetime',

            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'viewed_at' => 'datetime',
        ];
    }

    public function client()
    {
        return $this->belongsTo(\App\Models\Clients\Client::class);
    }

    public function items()
    {
        return $this->hasMany(ContractItem::class, 'contract_id');
    }

    public function getTotalMonthlyAmountAttribute(): float
    {
        $total = 0;
        foreach ($this->items as $item) {
            if ($item->billing_interval === 'monthly') {
                $total += $item->line_total;
            }
        }
        return (float) $total;
    }

    public function getYearlyProfitAttribute(): float
    {
        $annualProfit = 0;
        foreach ($this->items->loadMissing('service.costRelations.cost') as $item) {
            $revenuePerPeriod = $item->line_total;
            $costPerPeriod = (float)($item->service ? $item->service->costRelations->sum(fn($cr) => $cr->cost->cost ?? 0) : 0) * (int)$item->quantity;

            $multiplier = match ($item->billing_interval) {
                'monthly' => 12,
                'quarterly' => 4,
                'yearly' => 1,
                default => 0,
            };

            $annualProfit += ($revenuePerPeriod - $costPerPeriod) * $multiplier;
        }
        return (float) $annualProfit;
    }

    /**
     * Generate a secure unique token for the contract if it doesn't have one.
     */
    public function generateSecureToken(): string
    {
        if (empty($this->secure_token)) {
            $this->secure_token = \Illuminate\Support\Str::random(64);
            $this->save();
        }
        return $this->secure_token;
    }

    /**
     * Check if the contract is in an editable state.
     */
    public function isEditable(): bool
    {
        return in_array($this->approval_status, ['draft', 'negotiation', 'quote_lost']);
    }

    /**
     * Check if the contract is ready to be sent or approved.
     */
    public function isReady(): bool
    {
        return $this->items()->count() > 0
            && !empty($this->terms_snapshot)
            && $this->start_date
            && $this->start_date->isFuture();
    }
}
