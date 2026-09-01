<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailConversationTicketSuppression extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_LIFTED = 'lifted';

    protected $fillable = [
        'account_id',
        'email_conversation_id',
        'conversation_key',
        'status',
        'reason_code',
        'suppressed_by',
        'source_ticket_id',
        'suppressed_at',
        'lifted_by',
        'lifted_at',
        'metadata',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'email_conversation_id' => 'integer',
        'source_ticket_id' => 'integer',
        'suppressed_at' => 'datetime',
        'lifted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EmailConversation::class, 'email_conversation_id');
    }

    public function sourceTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'source_ticket_id')->withTrashed();
    }

    public function suppressedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suppressed_by');
    }
}
