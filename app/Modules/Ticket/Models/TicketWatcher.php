<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketWatcher extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'role',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
