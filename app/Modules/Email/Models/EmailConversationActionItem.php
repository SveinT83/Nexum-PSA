<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmailConversationActionItem extends Model
{
    public const PERSONAL_PENDING = 'pending';

    public const PERSONAL_APPLIED = 'applied';

    public const PERSONAL_UNCHANGED = 'unchanged';

    public const PERSONAL_COALESCED = 'coalesced';

    public const PERSONAL_DENIED = 'denied';

    public const PERSONAL_STALE = 'stale';

    public const PERSONAL_FAILED = 'failed';

    public const PROVIDER_NOT_REQUESTED = 'not_requested';

    public const PROVIDER_PENDING = 'pending';

    public const PROVIDER_SUCCEEDED = 'succeeded';

    public const PROVIDER_UNCHANGED = 'unchanged';

    public const PROVIDER_DENIED = 'denied';

    public const PROVIDER_STALE = 'stale';

    public const PROVIDER_FAILED = 'failed';

    public const PROVIDER_CONFLICTED = 'conflicted';

    protected $table = 'email_conversation_action_items';

    protected $fillable = [
        'public_id',
        'run_id',
        'ordinal',
        'account_id',
        'email_conversation_id',
        'email_message_id',
        'email_mailbox_placement_id',
        'email_folder_id',
        'uid_namespace_id',
        'imap_uid_validity',
        'imap_uid',
        'access_epoch',
        'provider_binding_version',
        'placement_sync_version',
        'source_fingerprint',
        'item_fingerprint',
        'personal_selected',
        'personal_before',
        'personal_target',
        'personal_status',
        'personal_reason_code',
        'provider_selected',
        'provider_before',
        'provider_target',
        'provider_status',
        'provider_reason_code',
        'email_remote_operation_id',
        'claim_token',
        'claimed_at',
        'claim_expires_at',
        'personal_applied_at',
        'provider_reserved_at',
        'completed_at',
    ];

    protected $casts = [
        'run_id' => 'integer',
        'ordinal' => 'integer',
        'account_id' => 'integer',
        'email_conversation_id' => 'integer',
        'email_message_id' => 'integer',
        'email_mailbox_placement_id' => 'integer',
        'email_folder_id' => 'integer',
        'uid_namespace_id' => 'integer',
        'imap_uid_validity' => 'integer',
        'imap_uid' => 'integer',
        'access_epoch' => 'integer',
        'provider_binding_version' => 'integer',
        'placement_sync_version' => 'integer',
        'personal_selected' => 'boolean',
        'personal_before' => 'boolean',
        'personal_target' => 'boolean',
        'provider_selected' => 'boolean',
        'provider_before' => 'boolean',
        'provider_target' => 'boolean',
        'email_remote_operation_id' => 'integer',
        'claimed_at' => 'immutable_datetime',
        'claim_expires_at' => 'immutable_datetime',
        'personal_applied_at' => 'immutable_datetime',
        'provider_reserved_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            foreach ([
                'public_id',
                'run_id',
                'ordinal',
                'account_id',
                'email_conversation_id',
                'email_message_id',
                'email_mailbox_placement_id',
                'email_folder_id',
                'uid_namespace_id',
                'imap_uid_validity',
                'imap_uid',
                'access_epoch',
                'provider_binding_version',
                'placement_sync_version',
                'source_fingerprint',
                'item_fingerprint',
                'personal_selected',
                'personal_before',
                'personal_target',
                'provider_selected',
                'provider_before',
                'provider_target',
            ] as $field) {
                if ($item->isDirty($field)) {
                    throw new LogicException('Email conversation-action item snapshot is immutable.');
                }
            }
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailConversationActionRun::class, 'run_id');
    }

    public function remoteOperation(): BelongsTo
    {
        return $this->belongsTo(EmailRemoteOperation::class, 'email_remote_operation_id');
    }
}
