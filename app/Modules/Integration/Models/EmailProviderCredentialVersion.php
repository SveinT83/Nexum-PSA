<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailProviderCredentialVersion extends Model
{
    public const STATE_STAGED = 'staged';

    public const STATE_ACTIVE = 'active';

    public const STATE_RETIRED = 'retired';

    public const STATE_REVOKED = 'revoked';

    public const STATE_DESTROYED = 'destroyed';

    protected $table = 'integration_email_provider_credential_versions';

    protected $fillable = [
        'provider_integration_id',
        'version',
        'state',
        'imap_username_encrypted',
        'imap_secret_encrypted',
        'smtp_username_encrypted',
        'smtp_secret_encrypted',
        'credential_fingerprint',
        'verified_configuration_version',
        'verification_code',
        'staged_by',
        'verified_by',
        'activated_by',
        'revoked_by',
        'destroyed_by',
        'staged_at',
        'verified_at',
        'activated_at',
        'retired_at',
        'revoked_at',
        'destroyed_at',
    ];

    protected $hidden = [
        'imap_username_encrypted',
        'imap_secret_encrypted',
        'smtp_username_encrypted',
        'smtp_secret_encrypted',
        'credential_fingerprint',
    ];

    protected $casts = [
        'version' => 'integer',
        'verified_configuration_version' => 'integer',
        'staged_at' => 'datetime',
        'verified_at' => 'datetime',
        'activated_at' => 'datetime',
        'retired_at' => 'datetime',
        'revoked_at' => 'datetime',
        'destroyed_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(EmailProviderConnection::class, 'provider_integration_id');
    }

    public function hasCiphertext(): bool
    {
        return filled($this->imap_username_encrypted)
            && filled($this->imap_secret_encrypted)
            && filled($this->smtp_username_encrypted)
            && filled($this->smtp_secret_encrypted);
    }
}
