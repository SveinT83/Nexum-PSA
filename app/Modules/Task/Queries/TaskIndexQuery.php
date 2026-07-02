<?php

namespace App\Modules\Task\Queries;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TaskIndexQuery
{
    /**
     * Build the operational task list with sorting and compact filters.
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        $sort = $request->input('sort', 'updated_at');
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $query = Task::query()
            ->with(['status', 'queue', 'priority', 'assignee', 'creator', 'category', 'client', 'workContext', 'site', 'tags'])
            ->withCount(['children', 'checklistItems', 'dependencies']);

        if ($search = trim((string) $request->input('q'))) {
            $query->where(function (Builder $nested) use ($search) {
                $nested->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('site', fn (Builder $site) => $site->where('name', 'like', "%{$search}%"));
            });
        }

        if ($statusId = $request->integer('status_id')) {
            $query->where('status_id', $statusId);
        } elseif (! $request->boolean('include_done')) {
            $query->where(function (Builder $nested) {
                $nested->whereNull('completed_at')
                    ->whereHas('status', fn (Builder $status) => $status->where('is_done', false));
            });
        }

        if ($queueId = $request->integer('queue_id')) {
            $query->where('queue_id', $queueId);
        }

        if ($priorityId = $request->integer('priority_id')) {
            $query->where('priority_id', $priorityId);
        }

        if ($assignedTo = $request->integer('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($request->boolean('mine') && $request->user() instanceof User) {
            $query->where('assigned_to', $request->user()->id);
        }

        $this->applySort($query, $sort, $direction);

        return $query->paginate(25)->withQueryString();
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            'title' => $query->orderBy('title', $direction),
            'status' => $query->leftJoin('task_statuses as sort_statuses', 'tasks.status_id', '=', 'sort_statuses.id')
                ->orderBy('sort_statuses.sort_order', $direction)
                ->select('tasks.*'),
            'queue' => $query->leftJoin('ticket_queues as sort_queues', 'tasks.queue_id', '=', 'sort_queues.id')
                ->orderBy('sort_queues.name', $direction)
                ->select('tasks.*'),
            'priority' => $query->leftJoin('ticket_priorities as sort_priorities', 'tasks.priority_id', '=', 'sort_priorities.id')
                ->orderBy('sort_priorities.level', $direction)
                ->select('tasks.*'),
            'assignee' => $query->leftJoin((new User())->getTable().' as sort_assignees', 'tasks.assigned_to', '=', 'sort_assignees.id')
                ->orderBy('sort_assignees.name', $direction)
                ->select('tasks.*'),
            'due_at' => $query->orderByRaw('due_at is null')->orderBy('due_at', $direction),
            'estimated_minutes' => $query->orderBy('estimated_minutes', $direction),
            default => $query->orderBy('tasks.updated_at', $direction),
        };

        $query->orderBy('tasks.id', 'desc');
    }
}
