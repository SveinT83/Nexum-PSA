<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Services\TicketAssignmentEngine;
use App\Modules\Ticket\Services\TicketRuleEngine;
use App\Modules\Ticket\Services\TicketSlaResolver;
use Illuminate\Support\Facades\DB;

class StoreTicket
{
    public function __construct(
        private readonly EnsureTicketDefaults $defaults,
        private readonly TicketRuleEngine $ticketRuleEngine,
        private readonly TicketAssignmentEngine $ticketAssignmentEngine,
        private readonly TicketSlaResolver $ticketSlaResolver,
    ) {}

    public function handle(array $data, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($data, $actor) {
            $defaults = $this->defaults->handle();
            $data = $this->ticketRuleEngine->apply('on_create', array_merge([
                'channel' => 'manual',
                'ticket_type_id' => $defaults['type']->id,
                'queue_id' => $defaults['queue']->id,
                'priority_id' => $defaults['priority']->id,
            ], $data));
            $ticketType = TicketType::find($data['ticket_type_id'] ?? null) ?? $defaults['type'];
            $priority = TicketPriority::find($data['priority_id'] ?? null) ?? $defaults['priority'];
            $sla = $this->ticketSlaResolver->resolve($data, $priority);

            $ticket = Ticket::create([
                'ticket_key' => $this->nextTicketKey(),
                'type' => $ticketType->slug,
                'ticket_type_id' => $ticketType->id,
                'queue_id' => $data['queue_id'] ?? $defaults['queue']->id,
                'status_id' => $data['status_id'] ?? $defaults['status']->id,
                'priority_id' => $priority->id,
                'sla_id' => $sla['sla_id'],
                'sla_source' => $sla['sla_source'],
                'sla_source_id' => $sla['sla_source_id'],
                'sla_snapshot' => $sla['sla_snapshot'],
                'workflow_id' => $data['workflow_id'] ?? TicketWorkflow::query()->where('is_active', true)->where('is_default', true)->value('id'),
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
                'first_response_due_at' => $sla['first_response_due_at'],
                'resolve_due_at' => $sla['resolve_due_at'],
            ]);

            if (! empty($data['description']) && ($data['channel'] ?? 'manual') !== 'email') {
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

            if (array_key_exists('tag_ids', $data)) {
                // Ticket tags share the global taggables table with email, so keep the module pivot explicit.
                $ticket->tags()->syncWithPivotValues($this->normalizeTagIds($data['tag_ids']), ['module' => 'ticket']);
            }

            // Assignment is intentionally last so Ticket Rules can set queue/category/priority first.
            $this->ticketAssignmentEngine->assign($ticket);

            // Send assignment notification if the ticket was assigned to someone
            $ticket->refresh();
            if ($ticket->owner_id && $ticket->owner_id !== $actor?->id) {
                $owner = User::find($ticket->owner_id);
                if ($owner) {
                    $owner->notify(new TicketAssigned(
                        ticket: $ticket,
                        assignedBy: $actor?->name ?? 'System',
                    ));
                }
            }

            return $ticket->fresh(['tags']);
        });
    }

    private function normalizeTagIds(mixed $tagIds): array
    {
        return collect((array) $tagIds)
            ->filter(fn ($tagId) => is_numeric($tagId))
            ->map(fn ($tagId) => (int) $tagId)
            ->unique()
            ->values()
            ->all();
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
