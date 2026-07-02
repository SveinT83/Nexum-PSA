<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use App\Modules\Relationship\Support\RelationshipCapability;
use App\Modules\Relationship\Support\SyncStatus;
use App\Modules\Ticket\Actions\ChangeTicketStatus;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Validation\ValidationException;

class ReceiveRelationshipTicketStatus
{
    public function __construct(private readonly RecordSyncEvent $events) {}

    public function handle(NexumRelationship $relationship, string $remoteTicketId, array $data): Ticket
    {
        if (! $relationship->supports(RelationshipCapability::STATUS_SYNC)) {
            throw ValidationException::withMessages(['relationship' => 'Status sync is not enabled for this relationship.']);
        }

        $link = NexumSyncLink::query()
            ->where('relationship_id', $relationship->id)
            ->where('domain', 'ticket')
            ->where('remote_type', 'ticket')
            ->where('remote_id', $remoteTicketId)
            ->firstOrFail();

        $ticket = Ticket::query()->findOrFail($link->local_id);
        $remoteStatus = (string) $data['status'];
        $localStatusKey = collect($relationship->status_mapping ?? [])
            ->flip()
            ->get($remoteStatus, $remoteStatus);
        $status = TicketStatus::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('slug', $localStatusKey)->orWhere('name', $localStatusKey))
            ->first();

        if (! $status) {
            $link->forceFill([
                'sync_status' => SyncStatus::SKIPPED,
                'last_error' => 'Remote status '.$remoteStatus.' has no local mapping.',
            ])->save();

            $this->events->handle($relationship, [
                'sync_link_id' => $link->id,
                'direction' => 'inbound',
                'capability' => RelationshipCapability::STATUS_SYNC,
                'local_type' => Ticket::class,
                'local_id' => $ticket->id,
                'remote_type' => 'ticket',
                'remote_id' => $remoteTicketId,
                'event_type' => 'ticket_status_received',
                'outcome' => 'skipped',
                'error_code' => 'missing_status_mapping',
                'error_message' => 'Remote status '.$remoteStatus.' has no local mapping.',
            ]);

            return $ticket;
        }

        app(ChangeTicketStatus::class)->handle($ticket, $status, null, enforceWorkflow: false, syncRelationship: false);
        $link->markSynced();

        $this->events->handle($relationship, [
            'sync_link_id' => $link->id,
            'direction' => 'inbound',
            'capability' => RelationshipCapability::STATUS_SYNC,
            'local_type' => Ticket::class,
            'local_id' => $ticket->id,
            'remote_type' => 'ticket',
            'remote_id' => $remoteTicketId,
            'event_type' => 'ticket_status_received',
            'outcome' => 'synced',
        ]);

        return $ticket->refresh();
    }
}
