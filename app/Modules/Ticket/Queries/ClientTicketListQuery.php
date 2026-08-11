<?php

namespace App\Modules\Ticket\Queries;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Collection;

class ClientTicketListQuery
{
    /**
     * Return the complete non-deleted Ticket list for one Client when the actor may view Tickets.
     */
    public function forClient(Client $client, ?User $actor): Collection
    {
        if (! $actor?->can('ticket.view')) {
            return collect();
        }

        return Ticket::query()
            ->where('client_id', $client->getKey())
            ->with(['status', 'priority', 'queue', 'owner'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }
}
