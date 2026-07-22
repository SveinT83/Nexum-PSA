<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;

class AutoAdvanceTicketWorkflow
{
    public function __construct(
        private readonly TicketWorkflowRuntime $workflowRuntime,
        private readonly TransitionTicketWorkflow $transitionTicketWorkflow,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Published workflow compatibility
    |--------------------------------------------------------------------------
    |
    | Workflow definitions published before schema version 3 relied on an
    | implicit solution requirement trigger. Preserve that pinned behavior,
    | while every current definition advances only from explicit triggers.
    |
    */
    public function handle(Ticket $ticket, ?User $actor = null): ?Ticket
    {
        if (! $this->workflowRuntime->usesImplicitRequirementTriggers($ticket)) {
            return null;
        }

        $transition = $this->workflowRuntime->availableTransitions($ticket)
            ->first(function (array $transition) use ($ticket): bool {
                $decision = $this->workflowRuntime->transitionDecision($ticket, $transition);

                return ! (bool) data_get($decision, 'target_state.is_terminal', false)
                    && $this->workflowRuntime->transitionHasRequirements($transition)
                    && $decision['allowed'];
            });

        if (! $transition) {
            return null;
        }

        return $this->transitionTicketWorkflow->handle(
            $ticket,
            (string) $transition['transition_key'],
            $actor,
            enforceActionGuard: false,
            allowTerminal: false,
        );
    }
}
