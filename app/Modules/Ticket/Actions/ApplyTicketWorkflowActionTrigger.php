<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;

class ApplyTicketWorkflowActionTrigger
{
    public function __construct(
        private readonly TicketWorkflowRuntime $workflowRuntime,
        private readonly ChangeTicketStatus $changeTicketStatus,
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
        $transition = $this->workflowRuntime->transitionForAction($ticket, $action);

        if (! $transition || $this->workflowRuntime->transitionBlockedReason($ticket, $transition)) {
            return null;
        }

        $transition->loadMissing('toStatus');

        return $this->changeTicketStatus->handle($ticket, $transition->toStatus, $actor);
    }
}
