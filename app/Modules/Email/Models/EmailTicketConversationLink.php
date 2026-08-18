<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTicketConversationLink extends Model
{
    protected $table = 'email_ticket_conversation_links';

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_SECONDARY = 'secondary';

    public const AUDIENCE_CUSTOMER = 'customer';

    public const AUDIENCE_INTERNAL = 'internal';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UNLINKED = 'unlinked';

    protected $fillable = [
        'ticket_id',
        'email_message_id',
        'email_mailbox_placement_id',
        'account_id',
        'email_conversation_id',
        'linked_by',
        'conversation_key',
        'relationship_role',
        'audience',
        'status',
        'metadata',
        'linked_at',
        'unlinked_at',
    ];

    protected $casts = [
        'email_conversation_id' => 'integer',
        'metadata' => 'array',
        'linked_at' => 'datetime',
        'unlinked_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id')->withTrashed();
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(EmailMailboxPlacement::class, 'email_mailbox_placement_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EmailConversation::class, 'email_conversation_id');
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
