<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowTransition;

class TicketWorkflowRuntime
{
    public function workflowFor(Ticket $ticket): ?TicketWorkflow
    {
        $ticket->loadMissing('workflow');

        return $ticket->workflow
            ?: TicketWorkflow::query()->where('is_active', true)->where('is_default', true)->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, TicketWorkflowTransition>
     */
    public function availableTransitions(Ticket $ticket)
    {
        $workflow = $this->workflowFor($ticket);

        if (! $workflow) {
            return collect();
        }

        return $workflow->transitions()
            ->with('toStatus')
            ->where('from_status_id', $ticket->status_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function findTransition(Ticket $ticket, TicketStatus $targetStatus): ?TicketWorkflowTransition
    {
        return $this->availableTransitions($ticket)
            ->first(fn (TicketWorkflowTransition $transition) => (int) $transition->to_status_id === (int) $targetStatus->id);
    }

    public function canTransition(Ticket $ticket, TicketStatus $targetStatus): bool
    {
        if ((int) $ticket->status_id === (int) $targetStatus->id) {
            return true;
        }

        return (bool) $this->findTransition($ticket, $targetStatus);
    }

    public function blockedReason(Ticket $ticket, TicketStatus $targetStatus): ?string
    {
        if ($this->canTransition($ticket, $targetStatus)) {
            return null;
        }

        $workflow = $this->workflowFor($ticket);

        if (! $workflow) {
            return null;
        }

        $ticket->loadMissing('status');

        return sprintf(
            'Workflow "%s" does not allow transition from "%s" to "%s".',
            $workflow->name,
            $ticket->status?->name ?? 'current status',
            $targetStatus->name
        );
    }
}
