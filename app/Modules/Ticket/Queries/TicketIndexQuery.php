<?php

namespace App\Modules\Ticket\Queries;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TicketIndexQuery
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = Ticket::query()
            ->with(['queue', 'status', 'priority', 'category', 'client'])
            ->when($filters['q'] ?? null, function ($query, string $search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('ticket_key', 'like', '%' . $search . '%')
                        ->orWhere('subject', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            })
            ->when($filters['status_id'] ?? null, fn ($query, $statusId) => $query->where('status_id', $statusId))
            ->when($filters['queue_id'] ?? null, fn ($query, $queueId) => $query->where('queue_id', $queueId))
            ->when($filters['client_id'] ?? null, fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when(($filters['ownership'] ?? 'mine') === 'mine', fn ($query) => $query->where('owner_id', auth()->id()));

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->oldest('updated_at'),
            'priority' => $query
                ->orderBy(TicketPriority::select('level')->whereColumn('ticket_priorities.id', 'tickets.priority_id'))
                ->latest('updated_at'),
            'unread' => $query->orderByDesc('is_unread')->latest('updated_at'),
            default => $query->latest('updated_at'),
        };

        return $query->paginate($perPage)->withQueryString();
    }
}
