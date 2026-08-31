<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEmailOutboundEvent extends Model
{
    protected $fillable = [
        'ticket_email_outbound_communication_id', 'event_type', 'actor_id',
        'safe_reason_code', 'metadata', 'occurred_at',
    ];

    protected $casts = ['metadata' => 'array', 'occurred_at' => 'datetime'];

    public function communication(): BelongsTo
    {
        return $this->belongsTo(TicketEmailOutboundCommunication::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
