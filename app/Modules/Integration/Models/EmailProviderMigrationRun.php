<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EmailProviderMigrationRun extends Model
{
    protected $table = 'integration_email_provider_migration_runs';

    protected $fillable = [
        'public_id',
        'operation',
        'status',
        'scope_fingerprint',
        'account_count',
        'ready_count',
        'blocked_count',
        'applied_count',
        'created_by',
        'applied_by',
        'rolled_back_by',
        'rollback_of_run_id',
        'source_run_id',
        'preview_expires_at',
        'rollback_deadline_at',
        'started_at',
        'finished_at',
        'rolled_back_at',
    ];

    protected $hidden = ['scope_fingerprint'];

    protected $casts = [
        'account_count' => 'integer',
        'ready_count' => 'integer',
        'blocked_count' => 'integer',
        'applied_count' => 'integer',
        'preview_expires_at' => 'datetime',
        'rollback_deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(EmailProviderMigrationItem::class, 'migration_run_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Email provider migration history cannot be deleted.'));
    }
}
