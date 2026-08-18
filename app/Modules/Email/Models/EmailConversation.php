<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmailConversation extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'account_id',
        'conversation_key',
        'status',
        'subject',
        'first_email_message_id',
        'latest_email_message_id',
        'latest_email_mailbox_placement_id',
        'message_count',
        'active_placement_count',
        'provider_unread_count',
        'has_attachments',
        'first_message_at',
        'last_message_at',
        'metadata',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'first_email_message_id' => 'integer',
        'latest_email_message_id' => 'integer',
        'latest_email_mailbox_placement_id' => 'integer',
        'message_count' => 'integer',
        'active_placement_count' => 'integer',
        'provider_unread_count' => 'integer',
        'has_attachments' => 'boolean',
        'first_message_at' => 'datetime',
        'last_message_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(EmailMailboxPlacement::class, 'email_conversation_id');
    }

    public function firstMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'first_email_message_id');
    }

    public function latestMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'latest_email_message_id');
    }

    public function latestPlacement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'latest_email_mailbox_placement_id');
    }

    public function ticketLinks(): HasMany
    {
        return $this->hasMany(EmailTicketConversationLink::class, 'email_conversation_id');
    }

    public function classification(): HasOne
    {
        return $this->hasOne(EmailConversationClassification::class, 'email_conversation_id');
    }
}
