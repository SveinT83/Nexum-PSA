<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailProviderReconciliationFolder extends Model
{
    public const DISCOVERY_EXISTING = 'existing';

    public const DISCOVERY_NEW_AFTER_BASELINE = 'new_after_baseline';

    public const DISCOVERY_LOCAL_ONLY = 'local_only';

    public const REMOTE_DISCOVERY_STATES = [
        self::DISCOVERY_EXISTING,
        self::DISCOVERY_NEW_AFTER_BASELINE,
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_WAITING_FOR_IMPORTS = 'waiting_for_imports';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_MISSING_CANDIDATE = 'missing_candidate';

    public const STATUS_MISSING_CONFIRMED = 'missing_confirmed';

    public const STATUS_STALE = 'stale';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** End tuple, namespace, and scan-time placement snapshot were proven. */
    public const STABLE_EVIDENCE_REASON_CODES = [
        'stable_end_validated',
        'stable_absence_freeze',
        'stable_operation_projection',
        'stable_absence_projection',
    ];

    public const IMPORT_BASELINE_ONLY = 'baseline_only';

    public const IMPORT_LIVE = 'live';

    public const IMPORT_NEW_FOLDER_NO_RULES = 'new_folder_no_rules';

    public const SNAPSHOT_BASELINE = 'baseline';

    public const SNAPSHOT_SCAN_END = 'scan_end';

    public const SNAPSHOT_REMOTE_END = 'remote_end';

    public const SNAPSHOT_REMOTE_PROJECTION = 'remote_projection';

    public const SNAPSHOT_LOCAL_FREEZE = 'local_freeze';

    public const SNAPSHOT_LOCAL_PROJECTION = 'local_projection';

    public const SNAPSHOT_RUNNING = 'running';

    public const SNAPSHOT_COMPLETED = 'completed';

    public const METADATA_VERIFICATION_RUNNING = 'running';

    public const METADATA_VERIFICATION_COMPLETED = 'completed';

    public const METADATA_VERIFICATION_FAILED = 'failed';

    public const ITEM_SUMMARY_RUNNING = 'running';

    public const ITEM_SUMMARY_SEALED = 'sealed';

    protected $table = 'email_provider_reconciliation_folders';

    protected $fillable = [
        'email_provider_reconciliation_run_id',
        'account_id',
        'email_folder_id',
        'uid_namespace_id',
        'folder_path',
        'folder_name',
        'delimiter',
        'parent_path',
        'remote_id',
        'special_use',
        'provider_selectable',
        'provider_sync_enabled',
        'discovery_state',
        'status',
        'import_policy',
        'expected_uid_validity',
        'start_uid_validity',
        'end_uid_validity',
        'start_uid_next',
        'end_uid_next',
        'start_exists_count',
        'end_exists_count',
        'start_highest_modseq',
        'end_highest_modseq',
        'supports_modseq',
        'end_supports_modseq',
        'scan_through_uid',
        'next_uid',
        'baseline_max_placement_id',
        'baseline_placement_count',
        'placement_baseline_hash',
        'placement_scan_hash',
        'placement_snapshot_purpose',
        'placement_snapshot_status',
        'placement_snapshot_through_id',
        'placement_snapshot_cursor_id',
        'placement_snapshot_count',
        'placement_snapshot_hash',
        'placement_snapshot_batch_count',
        'placement_snapshot_started_at',
        'placement_snapshot_completed_at',
        'inventory_hash',
        'metadata_verification_status',
        'metadata_verification_next_uid',
        'metadata_verification_count',
        'metadata_verification_hash',
        'metadata_verification_batch_count',
        'metadata_verification_started_at',
        'metadata_verification_completed_at',
        'item_summary_status',
        'item_summary_through_id',
        'item_summary_cursor_id',
        'item_summary_missing_count',
        'item_summary_move_count',
        'item_summary_conflict_count',
        'item_summary_nonterminal',
        'item_summary_batch_count',
        'item_summary_started_at',
        'item_summary_completed_at',
        'batch_count',
        'observed_count',
        'import_count',
        'flag_change_count',
        'missing_count',
        'conflict_count',
        'reason_code',
        'scan_started_at',
        'last_progress_at',
        'finished_at',
    ];

    protected $casts = [
        'email_provider_reconciliation_run_id' => 'integer',
        'account_id' => 'integer',
        'email_folder_id' => 'integer',
        'uid_namespace_id' => 'integer',
        'provider_selectable' => 'boolean',
        'provider_sync_enabled' => 'boolean',
        'expected_uid_validity' => 'integer',
        'start_uid_validity' => 'integer',
        'end_uid_validity' => 'integer',
        'start_uid_next' => 'integer',
        'end_uid_next' => 'integer',
        'start_exists_count' => 'integer',
        'end_exists_count' => 'integer',
        'start_highest_modseq' => 'integer',
        'end_highest_modseq' => 'integer',
        'supports_modseq' => 'boolean',
        'end_supports_modseq' => 'boolean',
        'scan_through_uid' => 'integer',
        'next_uid' => 'integer',
        'baseline_max_placement_id' => 'integer',
        'baseline_placement_count' => 'integer',
        'placement_snapshot_through_id' => 'integer',
        'placement_snapshot_cursor_id' => 'integer',
        'placement_snapshot_count' => 'integer',
        'placement_snapshot_batch_count' => 'integer',
        'placement_snapshot_started_at' => 'datetime',
        'placement_snapshot_completed_at' => 'datetime',
        'metadata_verification_next_uid' => 'integer',
        'metadata_verification_count' => 'integer',
        'metadata_verification_batch_count' => 'integer',
        'metadata_verification_started_at' => 'datetime',
        'metadata_verification_completed_at' => 'datetime',
        'item_summary_through_id' => 'integer',
        'item_summary_cursor_id' => 'integer',
        'item_summary_missing_count' => 'integer',
        'item_summary_move_count' => 'integer',
        'item_summary_conflict_count' => 'integer',
        'item_summary_nonterminal' => 'boolean',
        'item_summary_batch_count' => 'integer',
        'item_summary_started_at' => 'datetime',
        'item_summary_completed_at' => 'datetime',
        'batch_count' => 'integer',
        'observed_count' => 'integer',
        'import_count' => 'integer',
        'flag_change_count' => 'integer',
        'missing_count' => 'integer',
        'conflict_count' => 'integer',
        'scan_started_at' => 'datetime',
        'last_progress_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(
            EmailProviderReconciliationRun::class,
            'email_provider_reconciliation_run_id',
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'email_folder_id');
    }

    public function uidNamespace(): BelongsTo
    {
        return $this->belongsTo(EmailFolderUidNamespace::class, 'uid_namespace_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            EmailProviderReconciliationItem::class,
            'email_provider_reconciliation_folder_id',
        );
    }

    public function terminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETE,
            self::STATUS_MISSING_CANDIDATE,
            self::STATUS_MISSING_CONFIRMED,
            self::STATUS_STALE,
            self::STATUS_BLOCKED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /** @return array<string, bool|int|null> */
    public static function emptyItemSummary(): array
    {
        return [
            'item_summary_status' => null,
            'item_summary_through_id' => 0,
            'item_summary_cursor_id' => 0,
            'item_summary_missing_count' => 0,
            'item_summary_move_count' => 0,
            'item_summary_conflict_count' => 0,
            'item_summary_nonterminal' => false,
            'item_summary_batch_count' => 0,
            'item_summary_started_at' => null,
            'item_summary_completed_at' => null,
        ];
    }
}
