<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketApprovedScopeGuard;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseTicket
{
    public function __construct(
        private readonly ChangeTicketStatus $changeTicketStatus,
        private readonly TransitionTicketWorkflow $transitionWorkflow,
        private readonly TicketWorkflowRuntime $workflowRuntime,
        private readonly TicketActionGuard $guard,
        private readonly TicketApprovedScopeGuard $approvedScope,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Close shortcut
    |--------------------------------------------------------------------------
    |
    | Closing is a convenience action over the same status transition used by
    | manual status edits. Future workflow guards should hook into status change
    | validation before this action commits the closed status.
    |
    */
    public function handle(Ticket $ticket, ?User $actor = null, string $outcome = 'completed', ?string $reason = null): Ticket
    {
        if ($blocked = $this->guard->reason($ticket, TicketAction::CLOSE, $actor)) {
            throw ValidationException::withMessages(['close' => $blocked]);
        }

        if (! in_array($outcome, ['completed', 'customer_declined', 'cancelled', 'no_sale'], true)) {
            throw ValidationException::withMessages(['outcome' => 'Choose a valid close outcome.']);
        }

        if ($outcome !== 'completed' && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A close reason is required for a declined or cancelled Ticket.']);
        }

        if ($outcome === 'completed') {
            $this->approvedScope->assertWithinApprovedScope($ticket);
        }

        $terminalTransition = null;
        $closedStatus = null;

        if ($outcome === 'completed' && filled($ticket->workflow_id)) {
            $terminalTransitions = $this->workflowRuntime->availableTransitions($ticket)
                ->map(fn (array $transition) => $this->workflowRuntime->transitionDecision($ticket, $transition))
                ->filter(fn (array $decision) => (bool) data_get($decision, 'target_state.is_terminal', false))
                ->values();

            if ($terminalTransitions->count() !== 1) {
                throw ValidationException::withMessages([
                    'status_id' => $terminalTransitions->isEmpty()
                        ? 'The current workflow step has no finishing transition.'
                        : 'The current workflow step has more than one finishing transition. Use a specific workflow next-step action.',
                ]);
            }

            $terminalTransition = $terminalTransitions->first();
            if (! $terminalTransition['allowed']) {
                throw ValidationException::withMessages(['status_id' => $terminalTransition['disabled_reason']]);
            }
        } else {
            $closedStatus = $this->defaultClosedStatus();
            $this->changeTicketStatus->assertCanChange($ticket, $closedStatus, $actor, enforceWorkflow: false);
        }

        return DB::transaction(function () use ($ticket, $closedStatus, $terminalTransition, $actor, $outcome, $reason): Ticket {
            $ticket->forceFill([
                'close_outcome' => $outcome,
                'close_reason' => $reason,
                'updated_by' => $actor?->id,
            ])->save();

            if ($terminalTransition) {
                return $this->transitionWorkflow->handle(
                    $ticket,
                    (string) $terminalTransition['transition_key'],
                    $actor,
                    enforceActionGuard: false,
                );
            }

            return $this->changeTicketStatus->handle($ticket, $closedStatus, $actor, enforceWorkflow: false);
        });
    }

    private function defaultClosedStatus(): TicketStatus
    {
        return TicketStatus::query()->where('is_active', true)->where('is_closed', true)
            ->orderBy('sort_order')->orderBy('name')->first()
            ?? TicketStatus::create([
                'name' => 'Closed', 'slug' => 'closed', 'state' => 'closed',
                'is_default' => false, 'is_closed' => true, 'is_active' => true, 'sort_order' => 50,
            ]);
    }
}
