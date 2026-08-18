<?php

namespace App\Modules\Integration\Models;

use App\Modules\Email\Models\EmailAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmailProviderMigrationItem extends Model
{
    protected $table = 'integration_email_provider_migration_items';

    protected $fillable = [
        'migration_run_id',
        'email_account_id',
        'provider_integration_id',
        'credential_version_id',
        'status',
        'block_code',
        'legacy_fingerprint',
        'binding_fingerprint',
        'previous_source',
        'previous_provider_integration_id',
        'previous_binding_version',
        'staged_configuration_version',
        'staged_credential_version',
        'staged_at',
        'verified_at',
        'cutover_at',
        'rolled_back_at',
    ];

    protected $hidden = [
        'legacy_fingerprint',
        'binding_fingerprint',
    ];

    protected $casts = [
        'previous_binding_version' => 'integer',
        'staged_configuration_version' => 'integer',
        'staged_credential_version' => 'integer',
        'staged_at' => 'datetime',
        'verified_at' => 'datetime',
        'cutover_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailProviderMigrationRun::class, 'migration_run_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Email provider migration history cannot be deleted.'));
    }
}
