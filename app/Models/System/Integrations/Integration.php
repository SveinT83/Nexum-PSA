<?php

namespace App\Models\System\Integrations;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Integration\Models\IntegrationHubDomain;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class Integration extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'type', 'owner_scope', 'installation_key', 'client_id', 'client_site_id',
        'environment', 'server', 'status', 'config', 'secrets', 'last_sync_at', 'last_error',
        'is_healthy', 'health_status', 'health_failure_code', 'health_observed_at',
        'health_stale_after_seconds', 'last_successful_observation_at',
    ];

    protected $hidden = ['secrets'];

    protected $casts = [
        'config' => 'array', 'secrets' => 'array', 'last_sync_at' => 'datetime',
        'is_healthy' => 'boolean', 'health_observed_at' => 'datetime',
        'health_stale_after_seconds' => 'integer', 'last_successful_observation_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            if (empty($model->installation_key)) {
                $model->installation_key = (string) config('integration-hub.installation_key', 'installation');
            }
        });
    }

    public function getSecret($key)
    {
        if (! isset($this->secrets[$key])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->secrets[$key]);
        } catch (\Exception) {
            return null;
        }
    }

    public function setSecret($key, $value)
    {
        $secrets = $this->secrets ?? [];
        $secrets[$key] = Crypt::encryptString($value);
        $this->secrets = $secrets;
    }

    public function ownerClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function ownerSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function hubDomains(): HasMany
    {
        return $this->hasMany(IntegrationHubDomain::class, 'integration_id');
    }

    public function cloudFactoryClientLinks()
    {
        return $this->hasMany(\App\Modules\Integration\Models\CloudFactory\ClientLink::class);
    }

    public function cloudFactoryOffers()
    {
        return $this->hasMany(\App\Modules\Integration\Models\CloudFactory\Offer::class);
    }
}
