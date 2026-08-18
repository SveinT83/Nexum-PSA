<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Email\Models\Concerns\HasImmutableProviderBindingVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailHistoricalImportRun extends Model
{
    use HasImmutableProviderBindingVersion;

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_STALE = 'stale';

    public const PREVIEW_TTL_MINUTES = 15;

    public const DEFAULT_CAP = 100;

    public const HARD_CAP = 500;

    public const MAX_DATE_WINDOW_DAYS = 31;

    public const MAX_BATCH_SIZE = 50;

    protected $table = 'email_historical_import_runs';

    protected $fillable = [
        'account_id',
        'provider_binding_version',
        'requested_by',
        'cancelled_by',
        'status',
        'date_from',
        'date_to',
        'uid_from',
        'uid_to',
        'requested_cap',
        'effective_cap',
        'folder_scope_json',
        'provider_snapshot_json',
        'preview_fingerprint',
        'idempotency_key',
        'matched_count',
        'pending_count',
        'already_present_count',
        'imported_count',
        'skipped_count',
        'failed_count',
        'preview_expires_at',
        'queued_at',
        'started_at',
        'cancellation_requested_at',
        'finished_at',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'provider_binding_version' => 'integer',
        'requested_by' => 'integer',
        'cancelled_by' => 'integer',
        'date_from' => 'date',
        'date_to' => 'date',
        'uid_from' => 'integer',
        'uid_to' => 'integer',
        'requested_cap' => 'integer',
        'effective_cap' => 'integer',
        'folder_scope_json' => 'array',
        'provider_snapshot_json' => 'array',
        'matched_count' => 'integer',
        'pending_count' => 'integer',
        'already_present_count' => 'integer',
        'imported_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'preview_expires_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'cancellation_requested_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmailHistoricalImportItem::class, 'email_historical_import_run_id');
    }

    public function previewExpired(): bool
    {
        return ! $this->preview_expires_at || $this->preview_expires_at->isPast();
    }

    public function terminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_PARTIAL,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_STALE,
        ], true);
    }
}
