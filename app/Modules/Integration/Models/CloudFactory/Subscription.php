<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends CloudFactoryModel
{
    protected $table = 'cloudfactory_subscriptions';

    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'commitment_start_date' => 'date',
            'commitment_end_date' => 'date',
            'renewal_date' => 'date',
            'cancellation_deadline' => 'date',
            'unit_cost' => 'decimal:4',
            'unit_sale_price' => 'decimal:4',
            'allowed_actions' => 'array',
            'provider_payload' => 'array',
            'provider_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function clientLink(): BelongsTo
    {
        return $this->belongsTo(ClientLink::class, 'client_link_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Services::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contracts::class);
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(LicenceAmendment::class, 'subscription_id');
    }

    public function billingPeriods(): HasMany
    {
        return $this->hasMany(BillingPeriod::class, 'subscription_id');
    }
}
