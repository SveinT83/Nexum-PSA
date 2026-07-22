<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Signal\Actions\RecordSignal;
use App\Modules\Signal\Models\Signal;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Services\TicketAssignmentEngine;
use App\Modules\Ticket\Services\TicketRuleEngine;
use App\Modules\Ticket\Services\TicketSlaResolver;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Support\Facades\DB;

class StoreTicket
{
    public function __construct(
        private readonly EnsureTicketDefaults $defaults,
        private readonly TicketRuleEngine $ticketRuleEngine,
        private readonly TicketAssignmentEngine $ticketAssignmentEngine,
        private readonly TicketSlaResolver $ticketSlaResolver,
        private readonly TicketWorkflowDefinitionService $workflowDefinitions,
        private readonly ResolveWorkContext $workContexts,
        private readonly RecordSignal $recordSignal,
    ) {}

    public function handle(array $data, ?User $actor = null): Ticket
    {
        $signalEmissions = [];

        $ticket = DB::transaction(function () use ($data, $actor, &$signalEmissions) {
            $defaults = $this->defaults->handle();
            $data = $this->ticketRuleEngine->apply('on_create', array_merge([
                'channel' => 'manual',
                'ticket_type_id' => $defaults['type']->id,
                'queue_id' => $defaults['queue']->id,
                'priority_id' => $defaults['priority']->id,
            ], $data));
            $signalEmissions = $data['_signal_emissions'] ?? [];
            $ticketType = TicketType::find($data['ticket_type_id'] ?? null) ?? $defaults['type'];
            $priority = TicketPriority::find($data['priority_id'] ?? null) ?? $defaults['priority'];
            $sla = $this->ticketSlaResolver->resolve($data, $priority);
            $clientId = $data['client_id'] ?? null;
            $workContext = $this->workContexts->fromClientId($clientId);
            $workflowState = $this->workflowDefinitions->initialTicketState(
                isset($data['workflow_id']) ? (int) $data['workflow_id'] : null,
                isset($data['status_id']) ? (int) $data['status_id'] : (int) $defaults['status']->id,
            );

            $ticket = Ticket::create([
                'ticket_key' => $this->nextTicketKey(),
                'type' => $ticketType->slug,
                'ticket_type_id' => $ticketType->id,
                'queue_id' => $data['queue_id'] ?? $defaults['queue']->id,
                'status_id' => $workflowState['status_id'] ?? $data['status_id'] ?? $defaults['status']->id,
                'priority_id' => $priority->id,
                'sla_id' => $sla['sla_id'],
                'sla_source' => $sla['sla_source'],
                'sla_source_id' => $sla['sla_source_id'],
                'sla_snapshot' => $sla['sla_snapshot'],
                'workflow_id' => $workflowState['workflow_id'],
                'workflow_version_id' => $workflowState['workflow_version_id'],
                'workflow_state_key' => $workflowState['workflow_state_key'],
                'category_id' => $data['category_id'] ?? null,
                'client_id' => $clientId === '' ? null : $clientId,
                'work_context_id' => $workContext->id,
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
                    'metadata' => [
                        'created_from' => 'ticket_initial_description',
                        'is_default_initial_note' => true,
                    ],
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

        $this->recordTicketRuleSignals($ticket, $signalEmissions);

        return $ticket->fresh(['tags']);
    }

    private function recordTicketRuleSignals(Ticket $ticket, array $emissions): void
    {
        if (empty($emissions)) {
            return;
        }

        $ticket->loadMissing(['tags', 'contact']);
        $contactId = $ticket->contact?->contact_id;

        foreach ($emissions as $emission) {
            $signalType = (string) ($emission['signal_type'] ?? '');

            if ($signalType === '') {
                continue;
            }

            $existing = Signal::query()
                ->where('source_domain', 'ticket')
                ->where('source_type', $ticket->getMorphClass())
                ->where('source_id', $ticket->id)
                ->where('signal_type', $signalType)
                ->where('payload->ticket_rule_id', $emission['ticket_rule_id'] ?? null)
                ->where('payload->ticket_rule_action_index', $emission['ticket_rule_action_index'] ?? null)
                ->first();

            if ($existing) {
                continue;
            }

            $this->recordSignal->handle([
                'source_domain' => 'ticket',
                'source_type' => $ticket->getMorphClass(),
                'source_id' => $ticket->id,
                'contact_id' => $contactId,
                'client_id' => $ticket->client_id,
                'signal_type' => $signalType,
                'severity' => $emission['severity'] ?? 'info',
                'confidence' => $emission['confidence'] ?? 100,
                'summary' => $emission['summary'] ?? 'Ticket rule signal: '.str_replace('_', ' ', $signalType),
                'payload' => [
                    'ticket_id' => $ticket->id,
                    'ticket_key' => $ticket->ticket_key,
                    'ticket_rule_id' => $emission['ticket_rule_id'] ?? null,
                    'ticket_rule_name' => $emission['ticket_rule_name'] ?? null,
                    'ticket_rule_action_index' => $emission['ticket_rule_action_index'] ?? null,
                    'channel' => $ticket->channel,
                    'queue_id' => $ticket->queue_id,
                    'ticket_type_id' => $ticket->ticket_type_id,
                    'priority_id' => $ticket->priority_id,
                    'category_id' => $ticket->category_id,
                    'sla_id' => $ticket->sla_id,
                    'sla_source' => $ticket->sla_source,
                    'tags' => $ticket->tags->pluck('name')->values()->all(),
                    'note' => $emission['payload_note'] ?? null,
                ],
                'occurred_at' => $ticket->created_at ?: now(),
            ]);
        }
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
        $prefix = 'TD-'.now()->format('Y').'-';
        $next = (int) Ticket::withTrashed()->where('ticket_key', 'like', $prefix.'%')->count() + 1;

        do {
            $key = $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (Ticket::withTrashed()->where('ticket_key', $key)->exists());

        return $key;
    }
}
