<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowTransition;
use App\Modules\Ticket\Support\TicketSolutionPolicy;

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

    /**
     * @return \Illuminate\Support\Collection<int, TicketWorkflowTransition>
     */
    public function availableTransitionsWithRequirements(Ticket $ticket)
    {
        return $this->availableTransitions($ticket)
            ->filter(fn (TicketWorkflowTransition $transition) => (bool) $transition->manual_enabled)
            ->map(function (TicketWorkflowTransition $transition) use ($ticket) {
                $transition->setAttribute('disabled_reason', $this->transitionBlockedReason($ticket, $transition));

                return $transition;
            });
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

        return $this->blockedReason($ticket, $targetStatus) === null;
    }

    public function blockedReason(Ticket $ticket, TicketStatus $targetStatus): ?string
    {
        $workflow = $this->workflowFor($ticket);

        if (! $workflow) {
            return null;
        }

        $transition = $this->findTransition($ticket, $targetStatus);

        if ($transition) {
            return $this->transitionBlockedReason($ticket, $transition);
        }

        if ((int) $ticket->status_id === (int) $targetStatus->id) {
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

    public function transitionBlockedReason(Ticket $ticket, TicketWorkflowTransition $transition): ?string
    {
        $transition->loadMissing('toStatus');

        $state = $transition->workflow
            ? $transition->workflow->states()->where('ticket_status_id', $transition->to_status_id)->first()
            : null;

        $requiresNote = (bool) $transition->requires_note || (bool) $state?->requires_note;
        $requiresResponse = (bool) $transition->requires_response || (bool) $state?->requires_response;
        $requiresResolution = (bool) $transition->requires_resolution || (bool) $state?->requires_resolution;
        $requiresKnowledgeUpdate = (bool) $transition->requires_knowledge_update || (bool) $state?->requires_knowledge_update;

        if ($requiresNote && ! $this->hasInternalNote($ticket)) {
            return 'Add an internal note before using this workflow action.';
        }

        if ($requiresResponse && ! $this->hasPublicTechnicianResponse($ticket)) {
            return 'Send a response to the customer before using this workflow action.';
        }

        if ($requiresResolution && ! $this->hasSolutionMessage($ticket)) {
            return 'Mark a response as the solution before using this workflow action.';
        }

        if ($requiresKnowledgeUpdate && ! $this->hasDocumentationRequest($ticket)) {
            return 'Create a documentation follow-up before using this workflow action.';
        }

        return null;
    }

    public function transitionHasRequirements(TicketWorkflowTransition $transition): bool
    {
        $state = $transition->workflow
            ? $transition->workflow->states()->where('ticket_status_id', $transition->to_status_id)->first()
            : null;

        return (bool) $transition->requires_note
            || (bool) $transition->requires_response
            || (bool) $transition->requires_resolution
            || (bool) $transition->requires_knowledge_update
            || (bool) $state?->requires_note
            || (bool) $state?->requires_response
            || (bool) $state?->requires_resolution
            || (bool) $state?->requires_knowledge_update;
    }

    public function manualBlockedReason(Ticket $ticket, TicketWorkflowTransition $transition): ?string
    {
        if (! $transition->manual_enabled) {
            return 'This workflow action can only be triggered by a configured ticket action.';
        }

        return $this->transitionBlockedReason($ticket, $transition);
    }

    public function transitionForAction(Ticket $ticket, string $action): ?TicketWorkflowTransition
    {
        return $this->availableTransitions($ticket)
            ->first(fn (TicketWorkflowTransition $transition) => in_array($action, $transition->trigger_actions ?? [], true));
    }

    public function hasPublicTechnicianResponse(Ticket $ticket): bool
    {
        return $ticket->messages()
            ->where('author_type', 'user')
            ->where('type', 'customer_reply')
            ->where('visibility', 'public')
            ->exists();
    }

    public function hasSolutionMessage(Ticket $ticket): bool
    {
        return $ticket->messages()
            ->where('metadata->is_solution', true)
            ->where(function ($query) {
                $query->where('type', 'customer_reply');

                if (app(TicketSolutionPolicy::class)->allowsInternalSolutionNotes()) {
                    $query->orWhere('type', 'internal_note');
                }
            })
            ->exists();
    }

    private function hasInternalNote(Ticket $ticket): bool
    {
        return $ticket->messages()
            ->where('type', 'internal_note')
            ->exists();
    }

    private function hasDocumentationRequest(Ticket $ticket): bool
    {
        return $ticket->events()
            ->where('type', 'documentation_requested')
            ->exists();
    }
}
