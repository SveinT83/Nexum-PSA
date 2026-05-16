<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketWorkflowTransition;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;

class AutoAdvanceTicketWorkflow
{
    public function __construct(
        private readonly TicketWorkflowRuntime $workflowRuntime,
        private readonly ChangeTicketStatus $changeTicketStatus,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Requirement-driven workflow advance
    |--------------------------------------------------------------------------
    |
    | When a technician completes a required artifact, such as marking a reply
    | as the solution, the workflow may move forward automatically. Closing a
    | ticket remains a deliberate manual action.
    |
    */
    public function handle(Ticket $ticket, ?User $actor = null): ?Ticket
    {
        $transition = $this->workflowRuntime->availableTransitions($ticket)
            ->first(function (TicketWorkflowTransition $transition) use ($ticket) {
                $transition->loadMissing('toStatus');

                return ! $transition->toStatus?->is_closed
                    && $this->workflowRuntime->transitionHasRequirements($transition)
                    && $this->workflowRuntime->transitionBlockedReason($ticket, $transition) === null;
            });

        if (! $transition) {
            return null;
        }

        return $this->changeTicketStatus->handle($ticket, $transition->toStatus, $actor);
    }
}
