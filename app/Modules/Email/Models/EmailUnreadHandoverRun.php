<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailUnreadHandoverRun extends Model
{
    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_STALE = 'stale';

    public const STATUS_EXPIRED = 'expired';

    public const DEFAULT_CAP = 100;

    public const MAX_CAP = 500;

    public const PREVIEW_TTL_MINUTES = 15;

    protected $table = 'email_unread_handover_runs';

    protected $fillable = [
        'email_account_id',
        'requested_by',
        'target_user_id',
        'status',
        'reason',
        'folder_scope_json',
        'date_from',
        'date_to',
        'requested_cap',
        'access_epoch',
        'baseline_message_id',
        'authorization_fingerprint',
        'snapshot_fingerprint',
        'idempotency_key',
        'selected_count',
        'applied_count',
        'already_unread_count',
        'failed_count',
        'preview_expires_at',
        'applied_at',
        'finished_at',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'email_account_id' => 'integer',
        'requested_by' => 'integer',
        'target_user_id' => 'integer',
        'folder_scope_json' => 'array',
        'date_from' => 'immutable_datetime',
        'date_to' => 'immutable_datetime',
        'requested_cap' => 'integer',
        'access_epoch' => 'integer',
        'baseline_message_id' => 'integer',
        'selected_count' => 'integer',
        'applied_count' => 'integer',
        'already_unread_count' => 'integer',
        'failed_count' => 'integer',
        'preview_expires_at' => 'immutable_datetime',
        'applied_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmailUnreadHandoverItem::class, 'email_unread_handover_run_id')
            ->orderBy('snapshot_order');
    }
}
