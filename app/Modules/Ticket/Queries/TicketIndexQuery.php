<?php

namespace App\Modules\Ticket\Queries;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TicketIndexQuery
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = Ticket::query()
            ->with(['queue', 'status', 'priority', 'sla', 'category', 'client', 'owner'])
            ->when($filters['q'] ?? null, function ($query, string $search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('ticket_key', 'like', '%' . $search . '%')
                        ->orWhere('subject', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            })
            ->when($filters['status_id'] ?? null, fn ($query, $statusId) => $query->where('status_id', $statusId))
            ->when($filters['queue_id'] ?? null, fn ($query, $queueId) => $query->where('queue_id', $queueId))
            // These operational filters are intentionally composable so techs can narrow queues during triage.
            ->when($filters['priority_id'] ?? null, fn ($query, $priorityId) => $query->where('priority_id', $priorityId))
            ->when($filters['category_id'] ?? null, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($filters['client_id'] ?? null, fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when(($filters['spam'] ?? 'hide') === 'only', fn ($query) => $query->where('is_spam', true), fn ($query) => $query->where('is_spam', false))
            ->when(($filters['lifecycle'] ?? 'open') === 'open', fn ($query) => $query->whereHas('status', fn ($statusQuery) => $statusQuery->where('is_closed', false)))
            ->when(($filters['lifecycle'] ?? 'open') === 'closed', fn ($query) => $query->whereHas('status', fn ($statusQuery) => $statusQuery->where('is_closed', true)))
            ->when($filters['unread'] ?? null, fn ($query) => $query->where('is_unread', true))
            ->when($filters['unassigned'] ?? null, fn ($query) => $query->whereNull('owner_id'))
            ->when(($filters['ownership'] ?? 'mine_unassigned') === 'mine', fn ($query) => $query->where('owner_id', auth()->id()))
            ->when(($filters['ownership'] ?? 'mine_unassigned') === 'mine_unassigned', function ($query) {
                $query->where(function ($ownerQuery) {
                    $ownerQuery->where('owner_id', auth()->id())
                        ->orWhereNull('owner_id');
                });
            });

        $sort = $filters['sort'] ?? 'newest';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        match ($sort) {
            'ticket' => $query->orderBy('ticket_key', $direction)->latest('updated_at'),
            'subject' => $query->orderBy('subject', $direction)->latest('updated_at'),
            'client' => $query
                ->orderBy(Client::select('name')->whereColumn('clients.id', 'tickets.client_id'), $direction)
                ->latest('updated_at'),
            'technician' => $query
                ->orderBy(User::select('name')->whereColumn((new User())->getTable().'.id', 'tickets.owner_id'), $direction)
                ->latest('updated_at'),
            'queue' => $query
                ->orderBy(TicketQueue::select('name')->whereColumn('ticket_queues.id', 'tickets.queue_id'), $direction)
                ->latest('updated_at'),
            'status' => $query
                ->orderBy(TicketStatus::select('name')->whereColumn('ticket_statuses.id', 'tickets.status_id'), $direction)
                ->latest('updated_at'),
            'updated' => $query->orderBy('updated_at', $direction)->orderBy('ticket_key'),
            'oldest' => $query->oldest('updated_at'),
            'priority' => $query
                ->orderBy(TicketPriority::select('level')->whereColumn('ticket_priorities.id', 'tickets.priority_id'), $direction)
                ->latest('updated_at'),
            'sla_name' => $query
                ->orderBy(Sla::select('name')->whereColumn((new Sla())->getTable().'.id', 'tickets.sla_id'), $direction)
                ->latest('updated_at'),
            'sla' => $query
                ->orderByRaw('CASE WHEN first_response_due_at IS NOT NULL AND first_response_due_at < ? AND first_responded_at IS NULL THEN 0 ELSE 1 END', [now()])
                ->orderByRaw('CASE WHEN resolve_due_at IS NOT NULL AND resolve_due_at < ? AND resolved_at IS NULL THEN 0 ELSE 1 END', [now()])
                ->orderByRaw('COALESCE(first_response_due_at, resolve_due_at, updated_at) ASC')
                ->latest('updated_at'),
            'unread' => $query->orderByDesc('is_unread')->latest('updated_at'),
            default => $query->latest('updated_at'),
        };

        return $query->paginate($perPage)->withQueryString();
    }
}
