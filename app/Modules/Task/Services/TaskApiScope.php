<?php

namespace App\Modules\Task\Services;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** Private Tasks and mismatched owner contexts never widen through nested API projections. */
final class TaskApiScope
{
    public function visible(Builder $query, User $actor): Builder
    {
        return $query->where(function (Builder $q) use ($actor): void {
            $q->whereNull('tasks.visibility')->orWhere('tasks.visibility', '!=', Task::VISIBILITY_PRIVATE)
                ->orWhere('tasks.created_by', $actor->id)->orWhere('tasks.assigned_to', $actor->id)
                ->orWhere(fn (Builder $owner) => $owner->where('tasks.owner_type', $actor->getMorphClass())->where('tasks.owner_id', $actor->id));
        });
    }

    public function assert(Task $task, User $actor): void
    {
        abort_unless($this->visible(Task::query(), $actor)->whereKey($task->id)->exists(), 404);
        $task->loadMissing('workContext', 'owner');
        if ($task->owner instanceof Ticket) {
            app(TicketMutationScopeGuard::class)->assert($task->owner);
            // Legacy internal Tickets have no stored context; the Task's own concrete context is still checked below.
            if ((int) $task->client_id !== (int) $task->owner->client_id || ($task->owner->work_context_id !== null && (int) $task->work_context_id !== (int) $task->owner->work_context_id)) {
                throw ValidationException::withMessages(['task' => 'The Task work context does not match its owner.']);
            }
        }
        if ($task->owner instanceof \App\Models\Clients\Client && (int) $task->client_id !== (int) $task->owner->id) {
            throw ValidationException::withMessages(['task' => 'The Task Client does not match its owner.']);
        }
        if ($task->client_id === null && $task->work_context_id === null) {
            return; // Legacy internal records have no stored context.
        }
        $context = $task->workContext;
        $matches = $context && ($task->client_id === null
            ? $context->type === 'internal' && $context->client_id === null
            : $context->type === 'client' && (int) $context->client_id === (int) $task->client_id);
        if (! $matches || ($task->owner instanceof Ticket
            && ((int) $task->client_id !== (int) $task->owner->client_id || ($task->owner->work_context_id !== null && (int) $task->work_context_id !== (int) $task->owner->work_context_id)))) {
            throw ValidationException::withMessages(['task' => 'The Task work context does not match its owner.']);
        }
        if ($task->owner instanceof Ticket) {
            app(TicketMutationScopeGuard::class)->assert($task->owner);
        }
    }
}
