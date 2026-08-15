<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationHubSetting extends Model
{
    protected $fillable = [
        'installation_key', 'enabled', 'grants_invalid_before', 'grant_ttl_seconds',
        'audit_retention_days', 'default_stale_after_seconds', 'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'grants_invalid_before' => 'datetime',
        'grant_ttl_seconds' => 'integer',
        'audit_retention_days' => 'integer',
        'default_stale_after_seconds' => 'integer',
    ];

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['installation_key' => (string) config('integration-hub.installation_key')],
            [
                'enabled' => false,
                'grant_ttl_seconds' => min(300, max(30, (int) config('integration-hub.grant_ttl_seconds', 300))),
                'audit_retention_days' => max(1, (int) config('integration-hub.audit_retention_days', 90)),
                'default_stale_after_seconds' => max(30, (int) config('integration-hub.default_stale_after_seconds', 900)),
            ],
        );
    }
}
