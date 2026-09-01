<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketSlaResolver;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ApplyTicketSla
{
    public function __construct(private readonly TicketSlaResolver $ticketSlaResolver) {}

    public function handle(
        Ticket $ticket,
        Sla $sla,
        ?User $actor = null,
        string $source = 'manual',
        bool $suppressWorkflowTrigger = false,
    ): Ticket {
        if (! in_array($source, ['manual', 'ticket_rule'], true)) {
            throw new InvalidArgumentException('The SLA source is not supported.');
        }
        if ($source === 'ticket_rule' && ! $actor?->isSystemActor()) {
            throw new InvalidArgumentException('Ticket Rule SLA changes require a managed system actor.');
        }

        $changed = false;
        $result = DB::transaction(function () use ($ticket, $sla, $actor, $source, &$changed): Ticket {
            $ticket = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $ticket->loadMissing('priority');

            $before = $this->evidence($ticket);
            $resolution = $this->ticketSlaResolver->resolve(
                ['sla_id' => $sla->id],
                $ticket->priority,
                $ticket->created_at ?? now()
            );
            $desired = [
                'sla_id' => $resolution['sla_id'],
                'sla_source' => $source,
                'sla_source_id' => $sla->id,
                'sla_snapshot' => $resolution['sla_snapshot'],
                'first_response_due_at' => $resolution['first_response_due_at'],
                'resolve_due_at' => $resolution['resolve_due_at'],
            ];

            $comparison = [
                'sla_id' => $desired['sla_id'],
                'sla_source' => $desired['sla_source'],
                'sla_source_id' => $desired['sla_source_id'],
                'sla_snapshot' => $desired['sla_snapshot'],
                'first_response_due_at' => $desired['first_response_due_at']?->toISOString(),
                'resolve_due_at' => $desired['resolve_due_at']?->toISOString(),
            ];
            if ($before === $comparison) {
                return $ticket;
            }

            $changed = true;
            $ticket->forceFill($desired + [
                'updated_by' => $actor?->id,
            ])->save();
            $ticket->refresh();

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'sla_applied',
                'message' => 'SLA policy applied: '.$sla->name.'.',
                'before' => $before,
                'after' => $this->evidence($ticket),
            ]);

            return $ticket;
        });

        if ($changed && ! $suppressWorkflowTrigger) {
            app(ApplyTicketWorkflowActionTrigger::class)->handle($result->refresh(), TicketAction::APPLY_SLA, $actor);
        }

        return $result->refresh();
    }

    /** @return array<string, mixed> */
    private function evidence(Ticket $ticket): array
    {
        return [
            'sla_id' => $ticket->sla_id === null ? null : (int) $ticket->sla_id,
            'sla_source' => $ticket->sla_source,
            'sla_source_id' => $ticket->sla_source_id === null ? null : (int) $ticket->sla_source_id,
            'sla_snapshot' => $ticket->sla_snapshot,
            'first_response_due_at' => $ticket->first_response_due_at?->toISOString(),
            'resolve_due_at' => $ticket->resolve_due_at?->toISOString(),
        ];
    }
}
