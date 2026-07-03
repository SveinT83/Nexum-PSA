<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ResolveRelationshipTicketSyncLink
{
    public function handle(NexumRelationship $relationship, string $ticketIdentifier): NexumSyncLink
    {
        $ticketIdentifier = trim($ticketIdentifier);
        $baseQuery = NexumSyncLink::query()
            ->where('relationship_id', $relationship->id)
            ->where('domain', 'ticket');

        $link = (clone $baseQuery)
            ->where('remote_type', 'ticket')
            ->where('remote_id', $ticketIdentifier)
            ->first();

        if ($link) {
            return $link;
        }

        if (ctype_digit($ticketIdentifier)) {
            $link = (clone $baseQuery)
                ->where('local_type', Ticket::class)
                ->where('local_id', (int) $ticketIdentifier)
                ->first();

            if ($link) {
                return $link;
            }
        }

        $localTicketId = Ticket::query()
            ->where('ticket_key', $ticketIdentifier)
            ->value('id');

        if ($localTicketId) {
            $link = (clone $baseQuery)
                ->where('local_type', Ticket::class)
                ->where('local_id', $localTicketId)
                ->first();

            if ($link) {
                return $link;
            }
        }

        throw (new ModelNotFoundException())->setModel(NexumSyncLink::class);
    }
}
