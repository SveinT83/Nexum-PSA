<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\TicketRuleStableJson;

final class TicketRuleTicketState
{
    public function fingerprint(Ticket $ticket): string
    {
        return TicketRuleStableJson::checksum($this->facts($ticket));
    }

    /** @return array<string, mixed> */
    public function facts(Ticket $ticket): array
    {
        $ticket->refresh();

        return [
            'ticket_id' => (int) $ticket->id,
            'ticket_type_id' => $ticket->ticket_type_id,
            'queue_id' => $ticket->queue_id,
            'status_id' => $ticket->status_id,
            'priority_id' => $ticket->priority_id,
            'sla_id' => $ticket->sla_id,
            'category_id' => $ticket->category_id,
            'client_id' => $ticket->client_id,
            'site_id' => $ticket->site_id,
            'contact_id' => $ticket->contact_id,
            'asset_id' => $ticket->asset_id,
            'impact' => $ticket->impact,
            'urgency' => $ticket->urgency,
            'owner_id' => $ticket->owner_id,
            'channel' => $ticket->channel,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'workflow_id' => $ticket->workflow_id,
            'workflow_version_id' => $ticket->workflow_version_id,
            'workflow_state_key' => $ticket->workflow_state_key,
            'tag_ids' => $ticket->tags()
                ->pluck('tags.id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
