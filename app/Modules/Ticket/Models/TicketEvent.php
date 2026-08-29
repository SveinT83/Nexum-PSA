<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'actor_id',
        'ticket_rule_run_id',
        'type',
        'before',
        'after',
        'message',
        'metadata',
    ];

    protected $casts = [
        'ticket_rule_run_id' => 'integer',
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function ticketRuleRun(): BelongsTo
    {
        return $this->belongsTo(TicketRuleRun::class, 'ticket_rule_run_id');
    }
}
