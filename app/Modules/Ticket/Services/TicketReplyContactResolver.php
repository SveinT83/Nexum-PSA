<?php

namespace App\Modules\Ticket\Services;

use App\Models\Clients\ClientUser;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Collection;

class TicketReplyContactResolver
{
    /** @return Collection<int, ClientUser> */
    public function options(Ticket $ticket): Collection
    {
        if (! $ticket->client_id) {
            return $ticket->contact && $ticket->contact->active && filled($ticket->contact->email)
                ? collect([$ticket->contact])
                : collect();
        }

        return ClientUser::query()
            ->whereHas('site', fn ($query) => $query->where('client_id', $ticket->client_id))
            ->where('active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderByDesc('is_default_for_client')
            ->orderBy('name')
            ->get(['id', 'client_site_id', 'name', 'email']);
    }

    public function resolve(Ticket $ticket, ?int $contactId = null): ?ClientUser
    {
        $resolvedId = $contactId ?: ($ticket->contact_id ? (int) $ticket->contact_id : null);

        if (! $resolvedId) {
            return null;
        }

        return $this->options($ticket)->firstWhere('id', $resolvedId);
    }
}
