<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;

class ApplyTicketWorkflowActionTrigger
{
    public function __construct(
        private readonly TicketWorkflowRuntime $workflowRuntime,
        private readonly TransitionTicketWorkflow $transitionTicketWorkflow,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Workflow action triggers
    |--------------------------------------------------------------------------
    |
    | Ticket work actions can advance a workflow when the current transition
    | explicitly lists that action as a valid trigger.
    |
    */
    public function handle(Ticket $ticket, string $action, ?User $actor = null): ?Ticket
    {
        foreach ($this->workflowRuntime->transitionsForAction($ticket, $action) as $transition) {
            $decision = $this->workflowRuntime->transitionDecision($ticket, $transition);
            if (! $decision['allowed'] || (bool) data_get($decision, 'target_state.is_terminal', false)) {
                continue;
            }

            try {
                return $this->transitionTicketWorkflow->handle(
                    $ticket,
                    (string) $transition['transition_key'],
                    $actor,
                    enforceActionGuard: false,
                    allowTerminal: false,
                );
            } catch (\Illuminate\Validation\ValidationException) {
                // The source state may have changed between decision and lock.
                return null;
            }
        }

        return null;
    }
}
