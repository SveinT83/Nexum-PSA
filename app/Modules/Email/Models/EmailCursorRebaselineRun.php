<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Email\Models\Concerns\HasImmutableProviderBindingVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCursorRebaselineRun extends Model
{
    use HasImmutableProviderBindingVersion;

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_STALE = 'stale';

    public const STATUS_FAILED = 'failed';

    public const PREVIEW_TTL_MINUTES = 15;

    protected $table = 'email_cursor_rebaseline_runs';

    protected $fillable = [
        'account_id',
        'provider_binding_version',
        'email_folder_id',
        'requested_by',
        'reason',
        'status',
        'idempotency_key',
        'preview_fingerprint',
        'old_uid_namespace_id',
        'new_uid_namespace_id',
        'old_uid_validity',
        'observed_uid_validity',
        'observed_uid_next',
        'old_live_start_uid',
        'new_live_start_uid',
        'old_placement_count',
        'retired_placement_count',
        'provider_snapshot_json',
        'blocker_codes_json',
        'preview_expires_at',
        'applied_at',
        'finished_at',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'provider_binding_version' => 'integer',
        'email_folder_id' => 'integer',
        'requested_by' => 'integer',
        'old_uid_namespace_id' => 'integer',
        'new_uid_namespace_id' => 'integer',
        'old_uid_validity' => 'integer',
        'observed_uid_validity' => 'integer',
        'observed_uid_next' => 'integer',
        'old_live_start_uid' => 'integer',
        'new_live_start_uid' => 'integer',
        'old_placement_count' => 'integer',
        'retired_placement_count' => 'integer',
        'provider_snapshot_json' => 'array',
        'blocker_codes_json' => 'array',
        'preview_expires_at' => 'datetime',
        'applied_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'email_folder_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function oldUidNamespace(): BelongsTo
    {
        return $this->belongsTo(EmailFolderUidNamespace::class, 'old_uid_namespace_id');
    }

    public function newUidNamespace(): BelongsTo
    {
        return $this->belongsTo(EmailFolderUidNamespace::class, 'new_uid_namespace_id');
    }

    public function previewExpired(): bool
    {
        return ! $this->preview_expires_at || $this->preview_expires_at->isPast();
    }
}
