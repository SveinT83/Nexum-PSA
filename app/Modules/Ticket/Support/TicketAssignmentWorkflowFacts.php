<?php

namespace App\Modules\Ticket\Support;

use App\Models\Core\User;
use App\Modules\Ticket\Contracts\WorkflowFactProvider;
use App\Modules\Ticket\Models\Ticket;

class TicketAssignmentWorkflowFacts implements WorkflowFactProvider
{
    public function supports(string $fact): bool
    {
        return str_starts_with($fact, 'assignment.');
    }

    public function catalog(): array
    {
        return [
            'assignment.owner_assigned' => ['label' => 'Ticket has an owner', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
            'assignment.current_owner_eligible' => ['label' => 'Current owner is eligible for this step', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
        ];
    }

    public function resolve(Ticket $ticket, string $fact, array $condition = []): array
    {
        $ticket->loadMissing(['owner', 'workflowVersion']);
        $state = collect($ticket->workflowVersion?->definition['states'] ?? [])->firstWhere('state_key', $ticket->workflow_state_key);
        $policy = $state['assignment_policy'] ?? [];
        $eligibleIds = collect($policy['eligible_user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        $required = array_values(array_filter($policy['required_permissions'] ?? []));
        $owner = $ticket->owner;
        $eligible = $owner instanceof User
            && ($eligibleIds->isEmpty() || $eligibleIds->contains((int) $owner->id))
            && collect($required)->every(fn (string $permission) => $owner->can($permission));

        return [
            'value' => $fact === 'assignment.owner_assigned' ? $owner !== null : $eligible,
            'evidence' => ['owner_id' => $owner?->id, 'state_key' => $ticket->workflow_state_key],
        ];
    }
}
