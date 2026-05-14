<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketRuleLog extends Model
{
    protected $fillable = [
        'ticket_rule_id',
        'ticket_id',
        'status',
        'actions_json',
        'message',
    ];

    protected $casts = [
        'actions_json' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TicketRule::class, 'ticket_rule_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
