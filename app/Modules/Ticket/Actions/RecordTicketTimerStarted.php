<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Validation\ValidationException;

class RecordTicketTimerStarted
{
    public function __construct(private readonly TicketActionGuard $guard) {}

    /** @return array{ticket: Ticket, transitioned: bool} */
    public function handle(Ticket $ticket, User $actor): array
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::START_TIMER, $actor)) {
            throw ValidationException::withMessages(['timer' => $reason]);
        }

        TicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor->id,
            'type' => 'timer_started',
            'message' => 'Technician started the Ticket timer.',
            'after' => [
                'started_at' => now()->toISOString(),
                'source' => 'ticket_stopwatch',
            ],
        ]);

        app(ClaimUnassignedTicket::class)->handle($ticket, $actor, 'timer_started');
        $advanced = app(ApplyTicketWorkflowActionTrigger::class)->handle(
            $ticket->refresh(),
            TicketAction::START_TIMER,
            $actor,
        );

        return [
            'ticket' => ($advanced ?: $ticket)->refresh(),
            'transitioned' => $advanced !== null,
        ];
    }
}
