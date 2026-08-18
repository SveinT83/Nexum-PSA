<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailMailboxPlacement extends Model
{
    protected $table = 'email_mailbox_placements';

    public const LOCAL_ACTIVE = 'active';

    public const LOCAL_HIDDEN = 'hidden';

    public const SYNC_SHADOW = 'shadow';

    public const SYNC_SYNCED = 'synced';

    public const SYNC_PENDING = 'pending';

    public const SYNC_ERROR = 'error';

    protected $fillable = [
        'email_message_id',
        'canonical_email_message_id',
        'email_conversation_id',
        'account_id',
        'email_folder_id',
        'uid_namespace_id',
        'provider',
        'folder_path',
        'remote_message_id',
        'imap_uid_validity',
        'imap_uid',
        'remote_modseq',
        'provider_seen',
        'provider_answered',
        'provider_flagged',
        'provider_deleted',
        'provider_draft',
        'flags_json',
        'labels_json',
        'local_state',
        'sync_status',
        'sync_version',
        'last_provider_reconciliation_run_id',
        'last_provider_observed_sync_version',
        'last_provider_observed_identity_hash',
        'last_provider_observed_at',
        'last_reconciled_at',
        'provider_missing_at',
        'sync_error_code',
        'sync_error_message',
    ];

    protected $casts = [
        'email_conversation_id' => 'integer',
        'canonical_email_message_id' => 'integer',
        'uid_namespace_id' => 'integer',
        'imap_uid_validity' => 'integer',
        'imap_uid' => 'integer',
        'remote_modseq' => 'integer',
        'provider_seen' => 'boolean',
        'provider_answered' => 'boolean',
        'provider_flagged' => 'boolean',
        'provider_deleted' => 'boolean',
        'provider_draft' => 'boolean',
        'flags_json' => 'array',
        'labels_json' => 'array',
        'sync_version' => 'integer',
        'last_provider_reconciliation_run_id' => 'integer',
        'last_provider_observed_sync_version' => 'integer',
        'last_provider_observed_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'provider_missing_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function canonicalMessage(): BelongsTo
    {
        return $this->belongsTo(EmailCanonicalMessage::class, 'canonical_email_message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EmailConversation::class, 'email_conversation_id');
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

    public function remoteOperations(): HasMany
    {
        return $this->hasMany(EmailRemoteOperation::class, 'email_mailbox_placement_id');
    }

    public function sentReconciliations(): HasMany
    {
        return $this->hasMany(EmailSentReconciliation::class, 'sent_email_mailbox_placement_id');
    }
}
