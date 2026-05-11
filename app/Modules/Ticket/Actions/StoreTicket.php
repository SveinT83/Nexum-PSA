<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

class StoreTicket
{
    public function __construct(private readonly EnsureTicketDefaults $defaults)
    {
    }

    public function handle(array $data, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($data, $actor) {
            $defaults = $this->defaults->handle();

            $ticket = Ticket::create([
                'ticket_key' => $this->nextTicketKey(),
                'type' => $data['type'] ?? 'support',
                'queue_id' => $data['queue_id'] ?? $defaults['queue']->id,
                'status_id' => $data['status_id'] ?? $defaults['status']->id,
                'priority_id' => $data['priority_id'] ?? $defaults['priority']->id,
                'category_id' => $data['category_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'owner_id' => $data['owner_id'] ?? $actor?->id,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
                'channel' => $data['channel'] ?? 'manual',
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'impact' => $data['impact'] ?? null,
                'urgency' => $data['urgency'] ?? null,
                'is_unread' => false,
            ]);

            if (! empty($data['description'])) {
                TicketMessage::create([
                    'ticket_id' => $ticket->id,
                    'author_id' => $actor?->id,
                    'author_type' => 'user',
                    'type' => 'internal_note',
                    'visibility' => 'internal',
                    'subject' => $ticket->subject,
                    'body' => $data['description'],
                ]);
            }

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'created',
                'message' => 'Ticket created.',
                'after' => [
                    'ticket_key' => $ticket->ticket_key,
                    'subject' => $ticket->subject,
                ],
            ]);

            return $ticket;
        });
    }

    private function nextTicketKey(): string
    {
        $prefix = 'TD-' . now()->format('Y') . '-';
        $next = (int) Ticket::withTrashed()->where('ticket_key', 'like', $prefix . '%')->count() + 1;

        do {
            $key = $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (Ticket::withTrashed()->where('ticket_key', $key)->exists());

        return $key;
    }
}
