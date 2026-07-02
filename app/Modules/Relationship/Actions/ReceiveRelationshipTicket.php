<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\SyncStatus;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Validation\ValidationException;

class ReceiveRelationshipTicket
{
    public function __construct(
        private readonly EnsureRelationshipTicketQueue $queues,
        private readonly RecordSyncEvent $events,
    ) {}

    public function handle(NexumRelationship $relationship, array $data): array
    {
        if (! $relationship->supports(RelationshipCapability::TICKET_SYNC)) {
            throw ValidationException::withMessages(['relationship' => 'Ticket sync is not enabled for this relationship.']);
        }

        $remoteId = (string) $data['source_ticket_id'];
        $link = NexumSyncLink::query()
            ->where('relationship_id', $relationship->id)
            ->where('domain', 'ticket')
            ->where('remote_type', 'ticket')
            ->where('remote_id', $remoteId)
            ->first();

        if ($link && $link->local_id) {
            $ticket = Ticket::query()->findOrFail($link->local_id);
            $created = false;
        } else {
            if (! $relationship->isProviderForClient() || ! $relationship->client_id) {
                throw ValidationException::withMessages([
                    'relationship' => 'Inbound remote tickets require a provider relationship linked to a client.',
                ]);
            }

            $queue = $this->queues->handle($relationship);
            $ticket = app(StoreTicket::class)->handle([
                'channel' => 'nexum_relationship',
                'queue_id' => $queue->id,
                'client_id' => $relationship->client_id,
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
            ]);

            $link = NexumSyncLink::query()->create([
                'relationship_id' => $relationship->id,
                'domain' => 'ticket',
                'local_type' => Ticket::class,
                'local_id' => $ticket->id,
                'remote_type' => 'ticket',
                'remote_id' => $remoteId,
                'remote_url' => $data['source_url'] ?? null,
                'direction' => 'inbound',
                'sync_status' => SyncStatus::SYNCED,
                'last_synced_at' => now(),
                'metadata' => [
                    'source_ticket_key' => $data['source_ticket_key'] ?? null,
                    'remote_client' => $data['client'] ?? null,
                ],
            ]);
            $created = true;
        }

        $link->markSynced([
            'remote_url' => $data['source_url'] ?? $link->remote_url,
            'metadata' => array_merge($link->metadata ?? [], [
                'source_ticket_key' => $data['source_ticket_key'] ?? null,
                'remote_client' => $data['client'] ?? null,
            ]),
        ]);

        $this->events->handle($relationship, [
            'sync_link_id' => $link->id,
            'direction' => 'inbound',
            'capability' => RelationshipCapability::TICKET_SYNC,
            'local_type' => Ticket::class,
            'local_id' => $ticket->id,
            'remote_type' => 'ticket',
            'remote_id' => $remoteId,
            'event_type' => $created ? 'ticket_received' : 'ticket_matched',
            'outcome' => 'synced',
        ]);

        return [$ticket->refresh(), $link->refresh(), $created];
    }
}
