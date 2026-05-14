<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketTimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'type',
        'started_at',
        'ended_at',
        'minutes',
        'cost_account',
        'note',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'minutes' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
