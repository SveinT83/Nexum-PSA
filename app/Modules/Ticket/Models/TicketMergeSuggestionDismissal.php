<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketMergeSuggestionDismissal extends Model
{
    protected $fillable = [
        'first_ticket_id',
        'second_ticket_id',
        'dismissed_by',
        'reason',
    ];

    public static function pairIds(Ticket $first, Ticket $second): array
    {
        $ids = [$first->id, $second->id];
        sort($ids);

        return [
            'first_ticket_id' => $ids[0],
            'second_ticket_id' => $ids[1],
        ];
    }

    public function firstTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'first_ticket_id');
    }

    public function secondTicket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'second_ticket_id');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
