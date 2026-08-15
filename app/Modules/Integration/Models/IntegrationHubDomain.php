<?php

namespace App\Modules\Integration\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationHubDomain extends Model
{
    use HasUuids;

    protected $fillable = [
        'installation_key', 'client_id', 'client_site_id', 'integration_id', 'environment',
        'hostname_ascii', 'hostname_unicode', 'provider_reference', 'lifecycle_state',
        'verification_status', 'observed_at', 'stale_after_seconds', 'last_verified_at', 'metadata',
    ];

    protected $casts = [
        'observed_at' => 'datetime', 'last_verified_at' => 'datetime',
        'stale_after_seconds' => 'integer', 'metadata' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
