<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Email\Models\Concerns\HasImmutableProviderBindingVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;
use LogicException;

class EmailRemoteOperation extends Model
{
    use HasImmutableProviderBindingVersion;

    protected $table = 'email_remote_operations';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUPERSEDED = 'superseded';

    public const FAILURE_TRANSIENT = 'transient';

    public const FAILURE_PERMANENT = 'permanent';

    public const FAILURE_AMBIGUOUS = 'ambiguous';

    public const FAILURE_AUTHORIZATION = 'authorization';

    public const FAILURE_STALE = 'stale';

    public const DEFAULT_MAX_ATTEMPTS = 5;

    protected $fillable = [
        'account_id',
        'provider_binding_version',
        'email_folder_id',
        'email_mailbox_placement_id',
        'requested_by',
        'provider',
        'operation_type',
        'status',
        'idempotency_key',
        'inverse_of_email_remote_operation_id',
        'source_folder_path',
        'target_folder_path',
        'request_json',
        'expected_placement_sync_version',
        'expected_provider_uid',
        'expected_uid_validity',
        'acknowledged_target_uid_validity',
        'acknowledged_target_uid',
        'expected_folder_updated_at',
        'provider_response_json',
        'result_snapshot_json',
        'result_snapshot_captured_at',
        'undo_verified_at',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_attempt_at',
        'reconciliation_required_at',
        'reconciled_at',
        'started_at',
        'acknowledged_at',
        'failed_at',
        'cancelled_at',
        'error_code',
        'error_message',
        'failure_classification',
        'status_reason_code',
        'status_reason_message',
    ];

    protected $casts = [
        'request_json' => 'array',
        'provider_binding_version' => 'integer',
        'provider_response_json' => 'array',
        'result_snapshot_json' => 'array',
        'result_snapshot_captured_at' => 'datetime',
        'undo_verified_at' => 'datetime',
        'expected_placement_sync_version' => 'integer',
        'expected_provider_uid' => 'integer',
        'expected_uid_validity' => 'integer',
        'acknowledged_target_uid_validity' => 'integer',
        'acknowledged_target_uid' => 'integer',
        'expected_folder_updated_at' => 'datetime',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'reconciliation_required_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'started_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $operation): void {
            if (! Schema::hasColumn('email_remote_operations', 'expected_provider_uid')) {
                return;
            }

            $operation->max_attempts ??= self::DEFAULT_MAX_ATTEMPTS;

            if ($operation->email_mailbox_placement_id) {
                $placement = EmailMailboxPlacement::query()->find($operation->email_mailbox_placement_id);
                if ($placement) {
                    $operation->expected_placement_sync_version ??= (int) $placement->sync_version;
                    $operation->expected_provider_uid ??= (int) $placement->imap_uid;
                    $operation->expected_uid_validity ??= (int) $placement->imap_uid_validity;
                }
            }

            if ($operation->email_folder_id) {
                $folder = EmailFolder::query()->find($operation->email_folder_id);
                if ($folder?->updated_at) {
                    $operation->expected_folder_updated_at ??= $folder->updated_at;
                }
            }
        });

        static::updating(function (self $operation): void {
            foreach ([
                'acknowledged_target_uid_validity',
                'acknowledged_target_uid',
            ] as $field) {
                if ($operation->isDirty($field)
                    && $operation->getRawOriginal($field) !== null) {
                    throw new LogicException('Authoritative provider target identity is immutable.');
                }
            }

            if ($operation->isDirty('result_snapshot_json')
                && $operation->getRawOriginal('result_snapshot_json') !== null) {
                throw new LogicException('Email remote operation result snapshots are immutable.');
            }

            if ($operation->isDirty('result_snapshot_captured_at')
                && $operation->getRawOriginal('result_snapshot_captured_at') !== null) {
                throw new LogicException('Email remote operation result snapshot timestamps are immutable.');
            }

            if ($operation->isDirty('inverse_of_email_remote_operation_id')
                && $operation->getRawOriginal('inverse_of_email_remote_operation_id') !== null) {
                throw new LogicException('Email remote operation inverse linkage is immutable.');
            }
        });

        static::saving(function (self $operation): void {
            if (! Schema::hasColumn('email_remote_operations', 'acknowledged_target_uid')) {
                return;
            }

            $uidValidity = $operation->acknowledged_target_uid_validity;
            $uid = $operation->acknowledged_target_uid;
            if (($uidValidity === null) !== ($uid === null)
                || ($uidValidity !== null && ((int) $uidValidity < 1 || (int) $uid < 1))) {
                throw new LogicException('Authoritative provider target identity is incomplete.');
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'email_folder_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'email_mailbox_placement_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function inverseOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'inverse_of_email_remote_operation_id');
    }

    public function inverseOperation(): HasOne
    {
        return $this->hasOne(self::class, 'inverse_of_email_remote_operation_id');
    }

    public function attemptRecords(): HasMany
    {
        return $this->hasMany(EmailRemoteOperationAttempt::class, 'email_remote_operation_id')
            ->orderBy('attempt_number');
    }

    public function hasReachedAttemptLimit(): bool
    {
        return $this->providerAttemptCount() >= max(1, (int) ($this->max_attempts ?: self::DEFAULT_MAX_ATTEMPTS));
    }

    public function providerAttemptCount(): int
    {
        $recorded = $this->relationLoaded('attemptRecords')
            ? $this->attemptRecords
                ->where('attempt_kind', EmailRemoteOperationAttempt::KIND_MUTATION)
                ->count()
            : $this->attemptRecords()
                ->where('attempt_kind', EmailRemoteOperationAttempt::KIND_MUTATION)
                ->count();

        // `attempts` is the pre-recovery aggregate mutation counter. Keep it
        // as the conservative floor for rows created before attempt evidence.
        return max((int) $this->attempts, $recorded);
    }

    public function canBeRetried(): bool
    {
        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
            return false;
        }

        // The attempt budget limits provider writes, not read-only evidence
        // reconciliation. An ambiguous row must remain inspectable even when
        // every allowed mutation attempt has already been consumed.
        if ($this->failure_classification === self::FAILURE_AMBIGUOUS) {
            if (in_array($this->operation_type, ['archive', 'trash', 'move'], true)
                && ((int) $this->acknowledged_target_uid <= 0
                    || (int) $this->acknowledged_target_uid_validity <= 0
                    || blank($this->target_folder_path))) {
                // The move reconciler intentionally refuses to search or
                // replay without immutable target evidence. A Retry button
                // would only repeat the same read-only unresolved result.
                return false;
            }

            return true;
        }

        if ($this->hasReachedAttemptLimit()) {
            return false;
        }

        return $this->failure_classification === null
            || $this->failure_classification === self::FAILURE_TRANSIENT;
    }
}
