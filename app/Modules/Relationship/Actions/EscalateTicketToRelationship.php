<?php

namespace App\Modules\Relationship\Actions;

use App\Models\Core\User;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\SyncStatus;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EscalateTicketToRelationship
{
    public function __construct(private readonly NexumRelationshipHttpClient $client)
    {
    }

    public function handle(Ticket $ticket, NexumRelationship $relationship, ?User $actor = null): NexumSyncLink
    {
        if (! $ticket->isPortalVisible()) {
            throw ValidationException::withMessages([
                'relationship_id' => 'Publish the ticket before escalating it to a Nexum relationship.',
            ]);
        }

        if (! $relationship->isActive() || ! $relationship->supports(RelationshipCapability::TICKET_SYNC)) {
            throw ValidationException::withMessages([
                'relationship_id' => 'The selected relationship is not active for ticket sync.',
            ]);
        }

        if ($relationship->isProviderForClient() && (int) $ticket->client_id !== (int) $relationship->client_id) {
            throw ValidationException::withMessages([
                'relationship_id' => 'Provider relationships can only be used for tickets belonging to their linked client.',
            ]);
        }

        $link = DB::transaction(function () use ($ticket, $relationship, $actor): NexumSyncLink {
            $link = NexumSyncLink::query()->firstOrCreate(
                [
                    'relationship_id' => $relationship->id,
                    'domain' => 'ticket',
                    'local_type' => Ticket::class,
                    'local_id' => $ticket->id,
                ],
                [
                    'remote_type' => 'ticket',
                    'direction' => 'outbound',
                    'sync_status' => SyncStatus::PENDING,
                    'metadata' => ['source_ticket_key' => $ticket->ticket_key],
                ]
            );

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'relationship_escalated',
                'message' => 'Ticket escalated through Nexum relationship '.$relationship->name.'.',
                'after' => [
                    'relationship_id' => $relationship->id,
                    'relationship_name' => $relationship->name,
                    'sync_link_id' => $link->id,
                ],
            ]);

            return $link;
        });

        $payload = [
            'source_ticket_id' => (string) $ticket->id,
            'source_ticket_key' => $ticket->ticket_key,
            'source_url' => route('tech.tickets.show', $ticket),
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status?->slug,
            'priority' => $ticket->priority?->name,
            'client' => [
                'id' => $ticket->client_id,
                'name' => $ticket->client?->name,
            ],
            'occurred_at' => now()->toISOString(),
        ];

        $result = $this->client->post($relationship, 'tickets', $payload, RelationshipCapability::TICKET_SYNC, $link, 'ticket_escalated');

        if ($result['ok']) {
            $remote = $result['data']['data'] ?? $result['data'];
            $link->markSynced([
                'remote_id' => (string) ($remote['remote_id'] ?? $remote['ticket_key'] ?? $remote['id'] ?? $link->remote_id),
                'remote_url' => $remote['url'] ?? $link->remote_url,
                'remote_checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ]);
        }

        return $link->refresh();
    }
}
