<?php

namespace App\Modules\Email\Models;

use App\Modules\Email\Models\Concerns\HasImmutableProviderBindingVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSentReconciliation extends Model
{
    use HasImmutableProviderBindingVersion;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_APPEND_STARTED = 'append_started';

    public const STATUS_APPENDED = 'appended';

    public const STATUS_APPEND_FAILED = 'append_failed';

    protected $table = 'email_sent_reconciliations';

    protected $fillable = [
        'email_log_id',
        'account_id',
        'provider_binding_version',
        'source_email_message_id',
        'source_email_mailbox_placement_id',
        'sent_email_message_id',
        'sent_email_mailbox_placement_id',
        'sent_email_folder_id',
        'rfc_message_id',
        'normalized_message_id',
        'idempotency_key',
        'status',
        'candidate_count',
        'last_checked_at',
        'reconciled_at',
        'status_message',
        'context_json',
    ];

    protected $casts = [
        'provider_binding_version' => 'integer',
        'candidate_count' => 'integer',
        'last_checked_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'context_json' => 'array',
    ];

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class, 'email_log_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'source_email_message_id');
    }

    public function sourcePlacement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'source_email_mailbox_placement_id');
    }

    public function sentMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'sent_email_message_id');
    }

    public function sentPlacement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'sent_email_mailbox_placement_id');
    }

    public function sentFolder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'sent_email_folder_id');
    }
}
