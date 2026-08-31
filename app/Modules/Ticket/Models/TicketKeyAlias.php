<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketKeyAlias extends Model
{
    protected $fillable = [
        'alias_key',
        'ticket_id',
        'source_ticket_id',
        'created_by',
        'reason_code',
        'metadata',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'source_ticket_id' => 'integer',
        'created_by' => 'integer',
        'metadata' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id')->withTrashed();
    }

    public function sourceTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'source_ticket_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
