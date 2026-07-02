<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Ticket\Models\Ticket;

class SyncTicketStatusToRelationship
{
    public function __construct(private readonly NexumRelationshipHttpClient $client) {}

    public function handle(int $ticketId): void
    {
        $ticket = Ticket::query()
            ->with(['status', 'syncLinks.relationship'])
            ->find($ticketId);

        if (! $ticket || ! $ticket->status) {
            return;
        }

        foreach ($ticket->syncLinks as $link) {
            $relationship = $link->relationship;

            if (! $relationship?->isActive() || ! $relationship->supports(RelationshipCapability::STATUS_SYNC) || ! $link->remote_id) {
                continue;
            }

            $mapping = $relationship->status_mapping ?? [];
            $remoteStatus = $mapping[$ticket->status->slug] ?? $mapping[$ticket->status->name] ?? $ticket->status->slug;

            $this->client->post(
                $relationship,
                'tickets/'.rawurlencode((string) $link->remote_id).'/status',
                [
                    'source_ticket_key' => $ticket->ticket_key,
                    'status' => $remoteStatus,
                    'local_status' => [
                        'id' => $ticket->status->id,
                        'name' => $ticket->status->name,
                        'slug' => $ticket->status->slug,
                        'is_closed' => (bool) $ticket->status->is_closed,
                    ],
                    'occurred_at' => now()->toISOString(),
                ],
                RelationshipCapability::STATUS_SYNC,
                $link,
                'ticket_status_synced'
            );
        }
    }
}
