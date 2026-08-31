<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTicketCorrelationConflict extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'email_message_id',
        'status',
        'candidate_ticket_ids',
        'evidence',
        'resolved_ticket_id',
        'resolved_by',
        'resolution_reason',
        'detected_at',
        'resolved_at',
    ];

    protected $casts = [
        'candidate_ticket_ids' => 'array',
        'evidence' => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    public function resolvedTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'resolved_ticket_id')->withTrashed();
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
