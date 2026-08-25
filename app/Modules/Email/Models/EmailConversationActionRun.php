<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EmailConversationActionRun extends Model
{
    public const OPERATION_ACKNOWLEDGE = 'acknowledge';

    public const SCOPE_ACTIVE_ACCOUNT_CONVERSATION = 'active_account_conversation';

    public const SCOPE_EXPLICIT_MULTI_ACCOUNT = 'explicit_multi_account';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_STALE = 'stale';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'email_conversation_action_runs';

    protected $fillable = [
        'public_id',
        'requested_by',
        'operation',
        'scope_kind',
        'active_email_account_id',
        'active_email_conversation_id',
        'target_personal_unread',
        'provider_seen_requested',
        'status',
        'item_cap',
        'account_count',
        'item_count',
        'personal_applied_count',
        'provider_pending_count',
        'provider_succeeded_count',
        'denied_count',
        'stale_count',
        'failed_count',
        'request_fingerprint',
        'scope_fingerprint',
        'idempotency_key',
        'error_code',
        'previewed_at',
        'expires_at',
        'started_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'requested_by' => 'integer',
        'active_email_account_id' => 'integer',
        'active_email_conversation_id' => 'integer',
        'target_personal_unread' => 'boolean',
        'provider_seen_requested' => 'boolean',
        'item_cap' => 'integer',
        'account_count' => 'integer',
        'item_count' => 'integer',
        'personal_applied_count' => 'integer',
        'provider_pending_count' => 'integer',
        'provider_succeeded_count' => 'integer',
        'denied_count' => 'integer',
        'stale_count' => 'integer',
        'failed_count' => 'integer',
        'previewed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            foreach ([
                'public_id',
                'requested_by',
                'operation',
                'scope_kind',
                'active_email_account_id',
                'active_email_conversation_id',
                'target_personal_unread',
                'provider_seen_requested',
                'item_cap',
                'account_count',
                'item_count',
                'request_fingerprint',
                'scope_fingerprint',
                'idempotency_key',
                'previewed_at',
                'expires_at',
            ] as $field) {
                if ($run->isDirty($field)) {
                    throw new LogicException('Email conversation-action preview evidence is immutable.');
                }
            }
        });
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmailConversationActionItem::class, 'run_id')->orderBy('ordinal');
    }
}
