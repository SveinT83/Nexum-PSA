<?php

namespace App\Modules\Ticket\Support;

use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Ticket\Models\Ticket;

class TicketMergeSnapshot
{
    public static function fingerprint(Ticket $ticket): string
    {
        $links = EmailTicketConversationLink::query()
            ->where('ticket_id', $ticket->id)
            ->selectRaw('COUNT(*) as link_count, MAX(updated_at) as latest_link_at')
            ->first();

        return hash('sha256', json_encode([
            'id' => (int) $ticket->id,
            'ticket_key' => (string) $ticket->ticket_key,
            'subject' => (string) $ticket->subject,
            'type' => (string) $ticket->type,
            'queue_id' => $ticket->queue_id ? (int) $ticket->queue_id : null,
            'status_id' => $ticket->status_id ? (int) $ticket->status_id : null,
            'owner_id' => $ticket->owner_id ? (int) $ticket->owner_id : null,
            'client_id' => $ticket->client_id ? (int) $ticket->client_id : null,
            'merged_into_ticket_id' => $ticket->merged_into_ticket_id ? (int) $ticket->merged_into_ticket_id : null,
            'deleted_at' => $ticket->deleted_at?->format('Y-m-d H:i:s'),
            'updated_at' => $ticket->updated_at?->format('Y-m-d H:i:s'),
            'email_link_count' => (int) ($links?->link_count ?? 0),
            'latest_email_link_at' => $links?->latest_link_at,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
