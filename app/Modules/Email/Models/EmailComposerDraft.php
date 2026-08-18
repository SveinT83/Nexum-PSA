<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Email\Models\Concerns\HasImmutableProviderBindingVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailComposerDraft extends Model
{
    use HasImmutableProviderBindingVersion;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SENT = 'sent';

    public const STATUS_DISCARDED = 'discarded';

    public const PROVIDER_DRAFT_LOCAL_ONLY = 'local_only';

    public const PROVIDER_DRAFT_PENDING = 'pending';

    public const PROVIDER_DRAFT_APPEND_RESERVED = 'append_reserved';

    public const PROVIDER_DRAFT_APPEND_STARTED = 'append_started';

    public const PROVIDER_DRAFT_SYNCED = 'synced';

    public const PROVIDER_DRAFT_DELETED = 'deleted';

    public const PROVIDER_DRAFT_ERROR = 'error';

    public const PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED = 'PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED';

    public const PROVIDER_DRAFT_APPEND_PREWRITE_FAILED = 'PROVIDER_DRAFT_APPEND_PREWRITE_FAILED';

    public const PROVIDER_DRAFT_APPEND_RESERVATION = 'PROVIDER_DRAFT_APPEND_RESERVATION';

    protected $table = 'email_composer_drafts';

    protected $fillable = [
        'user_id',
        'email_account_id',
        'provider_binding_version',
        'email_message_id',
        'email_mailbox_placement_id',
        'mode',
        'draft_key',
        'status',
        'to_recipients',
        'cc_recipients',
        'subject',
        'body_html',
        'body_text',
        'idempotency_key',
        'last_saved_at',
        'sent_at',
        'discarded_at',
        'provider_draft_status',
        'provider_draft_folder_path',
        'provider_draft_uid_validity',
        'provider_draft_uid',
        'provider_draft_message_id',
        'provider_draft_normalized_message_id',
        'provider_draft_synced_at',
        'provider_draft_deleted_at',
        'provider_draft_error_code',
        'provider_draft_error_message',
    ];

    protected $casts = [
        'provider_binding_version' => 'integer',
        'last_saved_at' => 'datetime',
        'sent_at' => 'datetime',
        'discarded_at' => 'datetime',
        'provider_draft_uid_validity' => 'integer',
        'provider_draft_uid' => 'integer',
        'provider_draft_synced_at' => 'datetime',
        'provider_draft_deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'email_mailbox_placement_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailComposerDraftAttachment::class, 'email_composer_draft_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Provider APPEND states that must survive local autosave. Resetting one of
     * these states could erase the only durable evidence that prevents a
     * duplicate Drafts copy after an uncertain provider outcome.
     */
    public function hasProtectedProviderAppendState(): bool
    {
        return in_array($this->provider_draft_status, [
            self::PROVIDER_DRAFT_PENDING,
            self::PROVIDER_DRAFT_APPEND_RESERVED,
            self::PROVIDER_DRAFT_APPEND_STARTED,
        ], true) || $this->hasUnresolvedProviderAppend();
    }

    public function hasUnresolvedProviderAppend(): bool
    {
        return $this->provider_draft_status === self::PROVIDER_DRAFT_ERROR
            && $this->provider_draft_error_code === self::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED;
    }

    /**
     * A never-synced local draft may bind when its first provider reservation
     * is claimed. Provider evidence, including an old UID awaiting replacement,
     * makes the original binding immutable. A confirmed deletion is safe to
     * bind again for a later draft generation.
     */
    public function mayChangeProviderBindingVersion(): bool
    {
        $originalStatus = $this->getRawOriginal('provider_draft_status');

        if ($originalStatus === self::PROVIDER_DRAFT_DELETED) {
            return true;
        }

        return in_array($originalStatus, [null, self::PROVIDER_DRAFT_LOCAL_ONLY], true)
            && blank($this->getRawOriginal('provider_draft_folder_path'))
            && (int) $this->getRawOriginal('provider_draft_uid_validity') === 0
            && (int) $this->getRawOriginal('provider_draft_uid') === 0;
    }
}
