<?php

namespace App\Modules\Ticket\Queries;

use App\Modules\Ticket\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TicketMessageIndexQuery
{
    public function paginate(Ticket $ticket, int $perPage): LengthAwarePaginator
    {
        return $ticket->messages()
            ->select([
                'id',
                'ticket_id',
                'author_type',
                'type',
                'visibility',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @return array<string, bool|string|null> */
    public function summary(Ticket $ticket): array
    {
        $firstRespondedAt = $ticket->first_responded_at;
        $firstResponseDueAt = $ticket->first_response_due_at;

        return [
            'has_messages' => $ticket->messages()->exists(),
            'has_first_response' => $firstRespondedAt !== null,
            'first_responded_at' => $firstRespondedAt?->toISOString(),
            'first_response_due_at' => $firstResponseDueAt?->toISOString(),
            'first_response_within_due_at' => $firstRespondedAt !== null && $firstResponseDueAt !== null
                ? $firstRespondedAt->lessThanOrEqualTo($firstResponseDueAt)
                : null,
        ];
    }
}
