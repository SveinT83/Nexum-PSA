<?php

namespace App\Modules\Integration\Models;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailProviderConnection extends Model
{
    protected $table = 'integration_email_provider_connections';

    protected $primaryKey = 'integration_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'integration_id',
        'driver',
        'status',
        'configuration_version',
        'verified_configuration_version',
        'verified_credential_version',
        'active_credential_version_id',
        'imap_host',
        'imap_port',
        'imap_transport',
        'imap_endpoint_policy_id',
        'imap_auth_type',
        'smtp_host',
        'smtp_port',
        'smtp_transport',
        'smtp_endpoint_policy_id',
        'smtp_auth_type',
        'trust_mode',
        'trusted_cidr_name',
        'private_endpoint_reason',
        'capabilities',
        'last_verification_code',
        'last_verified_at',
        'verification_claim_token',
        'verification_claim_configuration_version',
        'verification_claim_credential_version',
        'verification_claim_expires_at',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'imap_host',
        'smtp_host',
        'private_endpoint_reason',
        'verification_claim_token',
    ];

    protected $casts = [
        'configuration_version' => 'integer',
        'verified_configuration_version' => 'integer',
        'verified_credential_version' => 'integer',
        'imap_port' => 'integer',
        'smtp_port' => 'integer',
        'capabilities' => 'array',
        'last_verified_at' => 'datetime',
        'verification_claim_configuration_version' => 'integer',
        'verification_claim_credential_version' => 'integer',
        'verification_claim_expires_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class, 'integration_id');
    }

    public function credentialVersions(): HasMany
    {
        return $this->hasMany(EmailProviderCredentialVersion::class, 'provider_integration_id');
    }

    public function activeCredentialVersion(): BelongsTo
    {
        return $this->belongsTo(EmailProviderCredentialVersion::class, 'active_credential_version_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(EmailProviderEvent::class, 'provider_integration_id');
    }

    public function emailAccounts(): HasMany
    {
        return $this->hasMany(EmailAccount::class, 'provider_integration_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
