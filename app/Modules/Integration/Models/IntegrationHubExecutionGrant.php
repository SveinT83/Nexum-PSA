<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IntegrationHubExecutionGrant extends Model
{
    use HasUuids;

    protected $fillable = [
        'grant_id_hash', 'issuer', 'audience', 'key_id', 'service_actor_key',
        'issued_by_token_id', 'actor_id', 'workload_id', 'installation_key',
        'capability_id', 'capability_key', 'capability_version', 'client_ids',
        'site_ids', 'integration_ids', 'environment', 'correlation_id',
        'policy_digest', 'claims_digest', 'issued_at', 'not_before', 'expires_at',
        'used_at', 'revoked_at', 'revocation_reason',
    ];

    protected $hidden = ['grant_id_hash', 'claims_digest'];

    protected $casts = [
        'client_ids' => 'array', 'site_ids' => 'array', 'integration_ids' => 'array',
        'issued_at' => 'datetime', 'not_before' => 'datetime', 'expires_at' => 'datetime',
        'used_at' => 'datetime', 'revoked_at' => 'datetime',
    ];
}
