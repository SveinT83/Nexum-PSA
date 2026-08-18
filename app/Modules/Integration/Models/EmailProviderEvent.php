<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EmailProviderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'integration_email_provider_events';

    protected $fillable = [
        'event_key',
        'provider_integration_id',
        'actor_id',
        'event_type',
        'reason_code',
        'configuration_version',
        'credential_version',
        'operation_fingerprint',
        'occurred_at',
    ];

    protected $hidden = ['operation_fingerprint'];

    protected $casts = [
        'configuration_version' => 'integer',
        'credential_version' => 'integer',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Email provider events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Email provider events are append-only.'));
    }
}
