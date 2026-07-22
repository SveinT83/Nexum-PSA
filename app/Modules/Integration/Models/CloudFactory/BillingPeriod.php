<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\Clients\Client;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPeriod extends Model
{
    protected $table = 'cloudfactory_billing_periods';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'quantity' => 'decimal:2',
            'unit_price_ex_vat' => 'decimal:4',
            'confirmed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(LicenceAmendment::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }
}
