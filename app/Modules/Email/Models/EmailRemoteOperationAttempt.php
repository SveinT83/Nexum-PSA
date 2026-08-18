<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmailRemoteOperationAttempt extends Model
{
    protected $table = 'email_remote_operation_attempts';

    public const KIND_MUTATION = 'mutation';

    public const KIND_PREFLIGHT = 'preflight';

    public const KIND_RECONCILIATION = 'reconciliation';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FINISHED = 'finished';

    protected $fillable = [
        'email_remote_operation_id',
        'attempt_number',
        'attempt_kind',
        'trigger',
        'triggered_by',
        'status',
        'outcome',
        'failure_classification',
        'reason_code',
        'reason_message',
        'request_json',
        'response_json',
        'error_json',
        'started_at',
        'provider_started_at',
        'provider_finished_at',
        'finished_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'request_json' => 'array',
        'response_json' => 'array',
        'error_json' => 'array',
        'started_at' => 'datetime',
        'provider_started_at' => 'datetime',
        'provider_finished_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            if ($attempt->getOriginal('finished_at') !== null) {
                throw new LogicException('Completed email remote operation attempts are immutable.');
            }

            $promotingToMutation = $attempt->getOriginal('status') === self::STATUS_RUNNING
                && $attempt->status === self::STATUS_RUNNING
                && $attempt->getOriginal('attempt_kind') === self::KIND_PREFLIGHT
                && $attempt->attempt_kind === self::KIND_MUTATION
                && array_diff(array_keys($attempt->getDirty()), ['attempt_kind', 'updated_at']) === [];

            if ($promotingToMutation) {
                return;
            }

            if ($attempt->status !== self::STATUS_FINISHED || $attempt->finished_at === null) {
                throw new LogicException('An email remote operation attempt may only transition once from running to finished.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Email remote operation attempts are immutable audit evidence.');
        });
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(EmailRemoteOperation::class, 'email_remote_operation_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
