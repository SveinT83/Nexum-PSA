<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailOutboundSubmission extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_PROVIDER_WRITE_STARTED = 'provider_write_started';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_OUTCOME_UNRESOLVED = 'outcome_unresolved';

    public const STATUS_PROVIDER_NOT_ATTEMPTED = 'provider_not_attempted';

    public const STATUS_SENT_RECONCILED = 'sent_reconciled';

    protected $table = 'email_outbound_submissions';

    protected $fillable = [
        'public_id',
        'email_account_id',
        'actor_id',
        'email_composer_draft_id',
        'source_email_message_id',
        'source_email_mailbox_placement_id',
        'email_log_id',
        'email_sent_reconciliation_id',
        'mode',
        'caller_channel',
        'client_idempotency_key',
        'request_fingerprint',
        'draft_generation_id',
        'draft_version',
        'provider_binding_version',
        'email_signature_id',
        'signature_source',
        'attachment_manifest_hash',
        'reserved_message_id',
        'status',
        'result_code',
        'reason_code',
        'provider_write_started_at',
        'accepted_at',
        'reconciled_at',
    ];

    protected $casts = [
        'draft_version' => 'integer',
        'provider_binding_version' => 'integer',
        'provider_write_started_at' => 'datetime',
        'accepted_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $submission): void {
            $submission->public_id = $submission->public_id ?: (string) Str::uuid();
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(EmailComposerDraft::class, 'email_composer_draft_id');
    }

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class, 'email_log_id');
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(EmailSignature::class, 'email_signature_id');
    }

    public function sentReconciliation(): BelongsTo
    {
        return $this->belongsTo(EmailSentReconciliation::class, 'email_sent_reconciliation_id');
    }
}
