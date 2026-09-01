<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Models\EmailTicketConversationLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TicketEmailOutboundCommunication extends Model
{
    public const STATE_DRAFT = 'draft';
    public const STATE_RESERVED = 'reserved';
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_UNRESOLVED = 'unresolved';
    public const STATE_RECONCILED = 'reconciled';
    public const STATE_FAILED_PRE_SEND = 'failed_pre_send';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_STALE = 'stale';

    protected $fillable = [
        'public_id', 'ticket_id', 'email_ticket_conversation_link_id', 'email_account_id',
        'email_conversation_id', 'source_email_message_id', 'source_email_mailbox_placement_id',
        'ticket_message_id', 'email_composer_draft_id', 'email_outbound_submission_id',
        'reconciled_sent_email_message_id', 'reconciled_sent_email_mailbox_placement_id',
        'operation_kind', 'audience', 'state', 'recipient_fingerprint', 'thread_fingerprint',
        'subject_fingerprint', 'source_fingerprint', 'attachment_manifest_hash',
        'signature_fingerprint', 'provider_binding_version', 'idempotency_key', 'actor_id',
        'version', 'safe_reason_code', 'reserved_at', 'accepted_at', 'reconciled_at',
    ];

    protected $casts = [
        'provider_binding_version' => 'integer',
        'version' => 'integer',
        'reserved_at' => 'datetime',
        'accepted_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $communication) => $communication->public_id ??= (string) Str::uuid());
    }

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function relationship(): BelongsTo { return $this->belongsTo(EmailTicketConversationLink::class, 'email_ticket_conversation_link_id'); }
    public function account(): BelongsTo { return $this->belongsTo(EmailAccount::class, 'email_account_id'); }
    public function conversation(): BelongsTo { return $this->belongsTo(EmailConversation::class, 'email_conversation_id'); }
    public function sourceMessage(): BelongsTo { return $this->belongsTo(EmailMessage::class, 'source_email_message_id'); }
    public function sourcePlacement(): BelongsTo { return $this->belongsTo(EmailMailboxPlacement::class, 'source_email_mailbox_placement_id'); }
    public function ticketMessage(): BelongsTo { return $this->belongsTo(TicketMessage::class, 'ticket_message_id'); }
    public function draft(): BelongsTo { return $this->belongsTo(EmailComposerDraft::class, 'email_composer_draft_id'); }
    public function submission(): BelongsTo { return $this->belongsTo(EmailOutboundSubmission::class, 'email_outbound_submission_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
    public function events(): HasMany { return $this->hasMany(TicketEmailOutboundEvent::class); }
}
