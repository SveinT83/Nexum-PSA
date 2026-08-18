<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Email\Models\Concerns\HasImmutableProviderBindingVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailProviderReconciliationRun extends Model
{
    use HasImmutableProviderBindingVersion;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING_FOR_IMPORTS = 'waiting_for_imports';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_CONFLICTS = 'completed_with_conflicts';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_STALE = 'stale';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_CANCELLED = 'cancelled';

    public const PHASE_DISCOVER_START = 'discover_start';

    public const PHASE_DISCOVER_LOCAL = 'discover_local';

    public const PHASE_SCAN = 'scan';

    public const PHASE_IMPORTS = 'imports';

    public const PHASE_FINALIZE = 'finalize';

    public const PHASE_DISCOVER_END = 'discover_end';

    public const PHASE_SUMMARY = 'summary';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_IDLE = 'idle';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_CATCHUP = 'catchup';

    public const LOCAL_FOLDER_SNAPSHOT_RUNNING = 'running';

    public const LOCAL_FOLDER_SNAPSHOT_COMPLETED = 'completed';

    public const AUTOMATION_SCOPE_UNSAFE_CODE = 'provider_reconciliation_automation_scope_unsafe';

    public const FINAL_SUMMARY_FOLDERS = 'folders';

    public const FINAL_SUMMARY_ITEMS = 'items';

    public const FINAL_SUMMARY_SEALED = 'sealed';

    /**
     * Exact states that retain the per-account active slot and must block a
     * provider credential cutover or rollback.
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
        self::STATUS_WAITING_FOR_IMPORTS,
        self::STATUS_CANCELLING,
    ];

    protected $table = 'email_provider_reconciliation_runs';

    protected $fillable = [
        'account_id',
        'requested_by',
        'cancelled_by',
        'provider',
        'trigger',
        'status',
        'phase',
        'active_slot',
        'idempotency_key',
        'provider_binding_version',
        'provider_configuration_version',
        'provider_credential_version',
        'provider_runtime_fingerprint',
        'start_folder_scope_hash',
        'end_folder_scope_hash',
        'local_folder_snapshot_status',
        'local_folder_snapshot_through_id',
        'local_folder_snapshot_cursor_id',
        'local_folder_snapshot_count',
        'local_folder_snapshot_hash',
        'local_folder_snapshot_batch_count',
        'local_folder_snapshot_started_at',
        'local_folder_snapshot_completed_at',
        'automation_scope_unsafe',
        'automation_scope_error_code',
        'automation_scope_unsafe_at',
        'final_summary_status',
        'final_summary_folder_through_id',
        'final_summary_folder_cursor_id',
        'final_summary_item_through_id',
        'final_summary_item_cursor_id',
        'final_summary_complete_folder_count',
        'final_summary_missing_count',
        'final_summary_move_count',
        'final_summary_conflict_count',
        'final_summary_error_count',
        'final_summary_blocked',
        'final_summary_failed',
        'final_summary_stale',
        'final_summary_automation_failed',
        'final_summary_batch_count',
        'final_summary_started_at',
        'final_summary_completed_at',
        'max_folders',
        'uid_batch_size',
        'provider_time_cap_seconds',
        'normal_interval_seconds',
        'folder_count',
        'complete_folder_count',
        'batch_count',
        'observed_count',
        'import_count',
        'flag_change_count',
        'missing_count',
        'move_count',
        'conflict_count',
        'error_count',
        'queued_at',
        'started_at',
        'last_progress_at',
        'retry_at',
        'cancellation_requested_at',
        'finished_at',
        'failure_code',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'requested_by' => 'integer',
        'cancelled_by' => 'integer',
        'active_slot' => 'integer',
        'provider_binding_version' => 'integer',
        'provider_configuration_version' => 'integer',
        'provider_credential_version' => 'integer',
        'local_folder_snapshot_through_id' => 'integer',
        'local_folder_snapshot_cursor_id' => 'integer',
        'local_folder_snapshot_count' => 'integer',
        'local_folder_snapshot_batch_count' => 'integer',
        'local_folder_snapshot_started_at' => 'datetime',
        'local_folder_snapshot_completed_at' => 'datetime',
        'automation_scope_unsafe' => 'boolean',
        'automation_scope_unsafe_at' => 'datetime',
        'final_summary_folder_through_id' => 'integer',
        'final_summary_folder_cursor_id' => 'integer',
        'final_summary_item_through_id' => 'integer',
        'final_summary_item_cursor_id' => 'integer',
        'final_summary_complete_folder_count' => 'integer',
        'final_summary_missing_count' => 'integer',
        'final_summary_move_count' => 'integer',
        'final_summary_conflict_count' => 'integer',
        'final_summary_error_count' => 'integer',
        'final_summary_blocked' => 'boolean',
        'final_summary_failed' => 'boolean',
        'final_summary_stale' => 'boolean',
        'final_summary_automation_failed' => 'boolean',
        'final_summary_batch_count' => 'integer',
        'final_summary_started_at' => 'datetime',
        'final_summary_completed_at' => 'datetime',
        'max_folders' => 'integer',
        'uid_batch_size' => 'integer',
        'provider_time_cap_seconds' => 'integer',
        'normal_interval_seconds' => 'integer',
        'folder_count' => 'integer',
        'complete_folder_count' => 'integer',
        'batch_count' => 'integer',
        'observed_count' => 'integer',
        'import_count' => 'integer',
        'flag_change_count' => 'integer',
        'missing_count' => 'integer',
        'move_count' => 'integer',
        'conflict_count' => 'integer',
        'error_count' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'last_progress_at' => 'datetime',
        'retry_at' => 'datetime',
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

    public function folders(): HasMany
    {
        return $this->hasMany(
            EmailProviderReconciliationFolder::class,
            'email_provider_reconciliation_run_id',
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            EmailProviderReconciliationItem::class,
            'email_provider_reconciliation_run_id',
        );
    }

    public function terminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_COMPLETED_WITH_CONFLICTS,
            self::STATUS_PARTIAL,
            self::STATUS_STALE,
            self::STATUS_BLOCKED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function cancellable(): bool
    {
        return ! $this->terminal()
            && $this->status !== self::STATUS_CANCELLING
            && $this->cancellation_requested_at === null;
    }

    /**
     * Materialize a provider-evidence ambiguity while the caller holds this
     * run's row lock. The database guard makes the transition monotonic even
     * for query-builder repair paths.
     */
    public function markAutomationScopeUnsafe(): void
    {
        if ($this->automation_scope_unsafe === true) {
            return;
        }

        $this->forceFill([
            'automation_scope_unsafe' => true,
            'automation_scope_error_code' => self::AUTOMATION_SCOPE_UNSAFE_CODE,
            'automation_scope_unsafe_at' => now(),
        ])->save();
    }

    /** @return array<string, bool|int|null> */
    public static function emptyFinalSummary(): array
    {
        return [
            'final_summary_status' => null,
            'final_summary_folder_through_id' => 0,
            'final_summary_folder_cursor_id' => 0,
            'final_summary_item_through_id' => 0,
            'final_summary_item_cursor_id' => 0,
            'final_summary_complete_folder_count' => 0,
            'final_summary_missing_count' => 0,
            'final_summary_move_count' => 0,
            'final_summary_conflict_count' => 0,
            'final_summary_error_count' => 0,
            'final_summary_blocked' => false,
            'final_summary_failed' => false,
            'final_summary_stale' => false,
            'final_summary_automation_failed' => false,
            'final_summary_batch_count' => 0,
            'final_summary_started_at' => null,
            'final_summary_completed_at' => null,
        ];
    }

    /** @param Builder<EmailProviderReconciliationRun> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('active_slot');
    }

    public static function accountHasActiveRun(int $accountId): bool
    {
        return self::query()
            ->where('account_id', $accountId)
            ->active()
            ->exists();
    }
}
