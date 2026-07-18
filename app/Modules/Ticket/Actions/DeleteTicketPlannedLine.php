<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPlannedLine;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteTicketPlannedLine
{
    public function __construct(private readonly TicketActionGuard $guard) {}

    public function handle(Ticket $ticket, TicketPlannedLine $line, User $actor): void
    {
        abort_unless((int) $line->ticket_id === (int) $ticket->id, 404);

        if ($reason = $this->guard->reason($ticket, TicketAction::ADD_PLANNED_COST, $actor)) {
            throw ValidationException::withMessages(['planned_line' => $reason]);
        }

        if (! in_array($line->status, ['planned', 'quoted'], true)) {
            throw ValidationException::withMessages(['planned_line' => 'Approved or converted scope cannot be deleted. Create a revised quote instead.']);
        }

        DB::transaction(function () use ($ticket, $line, $actor): void {
            $before = $line->only(['id', 'name', 'quantity', 'unit_price_ex_vat', 'status']);
            $line->delete();

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'planned_cost_removed',
                'message' => 'Planned cost removed.',
                'before' => $before,
            ]);

            app(InvalidateTicketWorkflowReviews::class)->handle($ticket, 'Planned commercial scope changed.', $actor);
        });
    }
}
