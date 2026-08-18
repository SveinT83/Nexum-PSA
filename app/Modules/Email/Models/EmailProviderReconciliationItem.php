<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailProviderReconciliationItem extends Model
{
    public const KIND_OBSERVATION = 'observation';

    public const KIND_IMPORT = 'import';

    public const KIND_MOVE_CANDIDATE = 'move_candidate';

    public const KIND_ABSENCE_CANDIDATE = 'absence_candidate';

    public const KIND_OPERATION_CONFLICT = 'operation_conflict';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING_FOR_BASELINE = 'waiting_for_baseline';

    public const STATUS_PROJECTED = 'projected';

    public const STATUS_ALREADY_PRESENT = 'already_present';

    public const STATUS_CONFIRMED_MOVE = 'confirmed_move';

    public const STATUS_CONFIRMED_MISSING = 'confirmed_missing';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_STALE = 'stale';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const AUTOMATION_PENDING = 'pending';

    public const AUTOMATION_AWAITING_CORRELATION = 'awaiting_correlation';

    public const AUTOMATION_RUNNING = 'running';

    public const AUTOMATION_AWAITING_NOTIFICATION_FANOUT = 'awaiting_notification_fanout';

    public const AUTOMATION_COMPLETED = 'completed';

    public const AUTOMATION_SUPPRESSED = 'suppressed';

    public const AUTOMATION_FAILED = 'failed';

    public const AUTOMATION_CANCELLED = 'cancelled';

    public const HISTORICAL_BASELINE_PENDING = 'pending';

    public const HISTORICAL_BASELINE_RUNNING = 'running';

    public const HISTORICAL_BASELINE_COMPLETED = 'completed';

    public const HISTORICAL_BASELINE_FAILED = 'failed';

    public const HISTORICAL_BASELINE_CANCELLED = 'cancelled';

    protected $table = 'email_provider_reconciliation_items';

    protected $fillable = [
        'email_provider_reconciliation_run_id',
        'email_provider_reconciliation_folder_id',
        'uid_namespace_id',
        'imap_uid',
        'kind',
        'status',
        'source_placement_id',
        'target_placement_id',
        'result_placement_id',
        'provider_modseq',
        'provider_seen',
        'provider_answered',
        'provider_flagged',
        'provider_deleted',
        'provider_draft',
        'custom_flags_json',
        'custom_flags_hash',
        'placement_sync_version_before',
        'placement_sync_version_after',
        'email_remote_operation_id',
        'email_provider_placement_finding_id',
        'identity_hash',
        'attempt_count',
        'error_code',
        'first_attempt_at',
        'last_attempt_at',
        'completed_at',
        'historical_baseline_required',
        'historical_baseline_status',
        'historical_baseline_max_id',
        'historical_baseline_cursor_id',
        'historical_baseline_claim_token',
        'historical_baseline_attempt_count',
        'historical_baseline_frozen_at',
        'historical_baseline_first_attempt_at',
        'historical_baseline_last_attempt_at',
        'historical_baseline_completed_at',
        'historical_baseline_error_code',
        'automation_required',
        'automation_status',
        'automation_claim_token',
        'automation_attempt_count',
        'automation_last_attempt_at',
        'automation_completed_at',
        'automation_error_code',
        'automation_rule_attempt_floor_id',
    ];

    protected $casts = [
        'email_provider_reconciliation_run_id' => 'integer',
        'email_provider_reconciliation_folder_id' => 'integer',
        'uid_namespace_id' => 'integer',
        'imap_uid' => 'integer',
        'source_placement_id' => 'integer',
        'target_placement_id' => 'integer',
        'result_placement_id' => 'integer',
        'provider_modseq' => 'integer',
        'provider_seen' => 'boolean',
        'provider_answered' => 'boolean',
        'provider_flagged' => 'boolean',
        'provider_deleted' => 'boolean',
        'provider_draft' => 'boolean',
        'custom_flags_json' => 'array',
        'placement_sync_version_before' => 'integer',
        'placement_sync_version_after' => 'integer',
        'email_remote_operation_id' => 'integer',
        'email_provider_placement_finding_id' => 'integer',
        'attempt_count' => 'integer',
        'first_attempt_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'historical_baseline_required' => 'boolean',
        'historical_baseline_max_id' => 'integer',
        'historical_baseline_cursor_id' => 'integer',
        'historical_baseline_attempt_count' => 'integer',
        'historical_baseline_frozen_at' => 'datetime',
        'historical_baseline_first_attempt_at' => 'datetime',
        'historical_baseline_last_attempt_at' => 'datetime',
        'historical_baseline_completed_at' => 'datetime',
        'automation_required' => 'boolean',
        'automation_attempt_count' => 'integer',
        'automation_last_attempt_at' => 'datetime',
        'automation_completed_at' => 'datetime',
        'automation_rule_attempt_floor_id' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(
            EmailProviderReconciliationRun::class,
            'email_provider_reconciliation_run_id',
        );
    }

    public function folderRun(): BelongsTo
    {
        return $this->belongsTo(
            EmailProviderReconciliationFolder::class,
            'email_provider_reconciliation_folder_id',
        );
    }

    public function uidNamespace(): BelongsTo
    {
        return $this->belongsTo(EmailFolderUidNamespace::class, 'uid_namespace_id');
    }

    public function sourcePlacement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'source_placement_id');
    }

    public function targetPlacement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'target_placement_id');
    }

    public function resultPlacement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'result_placement_id');
    }

    public function remoteOperation(): BelongsTo
    {
        return $this->belongsTo(EmailRemoteOperation::class, 'email_remote_operation_id');
    }

    public function providerPlacementFinding(): BelongsTo
    {
        return $this->belongsTo(
            EmailProviderPlacementFinding::class,
            'email_provider_placement_finding_id',
        );
    }

    public function terminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_PROJECTED,
            self::STATUS_ALREADY_PRESENT,
            self::STATUS_CONFIRMED_MOVE,
            self::STATUS_CONFIRMED_MISSING,
            self::STATUS_CONFLICT,
            self::STATUS_STALE,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function automationTerminal(): bool
    {
        return ! $this->automation_required || in_array($this->automation_status, [
            self::AUTOMATION_COMPLETED,
            self::AUTOMATION_SUPPRESSED,
            self::AUTOMATION_FAILED,
            self::AUTOMATION_CANCELLED,
        ], true);
    }

    public function historicalBaselineTerminal(): bool
    {
        return ! $this->historical_baseline_required || in_array(
            $this->historical_baseline_status,
            [
                self::HISTORICAL_BASELINE_COMPLETED,
                self::HISTORICAL_BASELINE_FAILED,
                self::HISTORICAL_BASELINE_CANCELLED,
            ],
            true,
        );
    }
}
