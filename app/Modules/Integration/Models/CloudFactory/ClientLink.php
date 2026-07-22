<?php

namespace App\Modules\Integration\Models\CloudFactory;

use App\Models\Clients\Client;
use App\Models\System\Integrations\Integration;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientLink extends CloudFactoryModel
{
    protected $table = 'cloudfactory_client_links';

    protected function casts(): array
    {
        return [
            'last_synced_snapshot' => 'array',
            'provider_payload' => 'array',
            'provider_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'client_link_id');
    }
}
