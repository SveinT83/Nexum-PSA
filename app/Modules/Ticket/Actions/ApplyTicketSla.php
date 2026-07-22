<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketSlaResolver;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;

class ApplyTicketSla
{
    public function __construct(private readonly TicketSlaResolver $ticketSlaResolver) {}

    public function handle(Ticket $ticket, Sla $sla, ?User $actor = null): Ticket
    {
        $result = DB::transaction(function () use ($ticket, $sla, $actor) {
            $ticket->loadMissing('priority');

            $before = [
                'sla_id' => $ticket->sla_id,
                'sla_source' => $ticket->sla_source,
                'first_response_due_at' => $ticket->first_response_due_at?->toISOString(),
                'resolve_due_at' => $ticket->resolve_due_at?->toISOString(),
            ];

            $resolution = $this->ticketSlaResolver->resolve(
                ['sla_id' => $sla->id],
                $ticket->priority,
                $ticket->created_at ?? now()
            );

            $ticket->forceFill([
                'sla_id' => $resolution['sla_id'],
                'sla_source' => 'manual',
                'sla_source_id' => $sla->id,
                'sla_snapshot' => $resolution['sla_snapshot'],
                'first_response_due_at' => $resolution['first_response_due_at'],
                'resolve_due_at' => $resolution['resolve_due_at'],
                'updated_by' => $actor?->id,
            ])->save();

            $ticket->refresh();

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'sla_applied',
                'message' => 'SLA policy applied: '.$sla->name.'.',
                'before' => $before,
                'after' => [
                    'sla_id' => $ticket->sla_id,
                    'sla_source' => $ticket->sla_source,
                    'first_response_due_at' => $ticket->first_response_due_at?->toISOString(),
                    'resolve_due_at' => $ticket->resolve_due_at?->toISOString(),
                ],
            ]);

            return $ticket;
        });

        app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::APPLY_SLA, $actor);

        return $result;
    }
}
