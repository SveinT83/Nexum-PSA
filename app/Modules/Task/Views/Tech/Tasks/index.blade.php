{{--
    Task index

    Shows the shared operational task queue. Tasks can belong to any domain, but
    this module owns filtering, sorting, and task-specific workflow signals.
--}}
@extends('layouts.default_tech')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">Tasks</h1>
    </div>
@endsection

@section('content')
    @php
        $hasFilters = filled($filters['status_id'] ?? null)
            || filled($filters['queue_id'] ?? null)
            || filled($filters['priority_id'] ?? null)
            || filled($filters['assigned_to'] ?? null)
            || ! empty($filters['mine'])
            || ! empty($filters['include_done']);

        $filterCount = collect(['status_id', 'queue_id', 'priority_id', 'assigned_to', 'mine', 'include_done'])
            ->filter(fn ($key) => filled($filters[$key] ?? null))
            ->count();

        $sortLink = function (string $column) use ($sort, $direction) {
            $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : 'asc';

            return request()->fullUrlWithQuery([
                'sort' => $column,
                'direction' => $nextDirection,
            ]);
        };

        $sortIcon = function (string $column) use ($sort, $direction) {
            if ($sort !== $column) {
                return 'bi-arrow-down-up';
            }

            return $direction === 'asc' ? 'bi-sort-alpha-down' : 'bi-sort-alpha-up';
        };

        $missing = fn ($value) => filled($value) ? $value : '—';
    @endphp

    <!-- ------------------------------------------------- -->
    <!-- Search and filters -->
    <!-- ------------------------------------------------- -->
    <form method="get" class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <div class="input-group input-group-sm flex-grow-1">
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Search tasks, clients, or sites">
                <button class="btn btn-outline-secondary" type="submit">Search</button>
                <button
                    class="btn btn-outline-secondary position-relative"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#taskFiltersCollapse"
                    aria-expanded="{{ $hasFilters ? 'true' : 'false' }}"
                    aria-controls="taskFiltersCollapse"
                    title="Filters">
                    <i class="bi bi-funnel"></i>
                    @if($filterCount > 0)
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-primary">{{ $filterCount }}</span>
                    @endif
                </button>
            </div>
        </div>

        <div class="collapse {{ $hasFilters ? 'show' : '' }}" id="taskFiltersCollapse">
            <div class="card-body border-top">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small text-muted" for="status_id">Status</label>
                        <select class="form-select form-select-sm" id="status_id" name="status_id">
                            <option value="">All statuses</option>
                            @foreach($statuses as $status)
                                <option value="{{ $status->id }}" @selected((string) ($filters['status_id'] ?? '') === (string) $status->id)>{{ $status->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted" for="queue_id">Queue</label>
                        <select class="form-select form-select-sm" id="queue_id" name="queue_id">
                            <option value="">All queues</option>
                            @foreach($queues as $queue)
                                <option value="{{ $queue->id }}" @selected((string) ($filters['queue_id'] ?? '') === (string) $queue->id)>{{ $queue->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted" for="priority_id">Priority</label>
                        <select class="form-select form-select-sm" id="priority_id" name="priority_id">
                            <option value="">All priorities</option>
                            @foreach($priorities as $priority)
                                <option value="{{ $priority->id }}" @selected((string) ($filters['priority_id'] ?? '') === (string) $priority->id)>{{ $priority->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted" for="assigned_to">Assignee</label>
                        <select class="form-select form-select-sm" id="assigned_to" name="assigned_to">
                            <option value="">Anyone</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" @selected((string) ($filters['assigned_to'] ?? '') === (string) $user->id)>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="mine" name="mine" value="1" @checked(! empty($filters['mine']))>
                            <label class="form-check-label" for="mine">Assigned to me</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="include_done" name="include_done" value="1" @checked(! empty($filters['include_done']))>
                            <label class="form-check-label" for="include_done">Show completed tasks</label>
                        </div>
                    </div>
                    <div class="col-md-9 text-md-end">
                        <a href="{{ route('tech.tasks.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                        <button class="btn btn-sm btn-primary" type="submit">Apply filters</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- Task list -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <span class="fw-semibold">Task List</span>
                <span class="small text-muted">{{ $tasks->total() }} total</span>
            </div>
            <x-buttons.addlink url="{{ route('tech.tasks.create') }}" class="mb-0">New Task</x-buttons.addlink>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>
                            <a href="{{ $sortLink('title') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Task <i class="bi {{ $sortIcon('title') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('status') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Status <i class="bi {{ $sortIcon('status') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('assignee') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Assignee <i class="bi {{ $sortIcon('assignee') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('queue') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Queue <i class="bi {{ $sortIcon('queue') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('priority') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Priority <i class="bi {{ $sortIcon('priority') }}"></i>
                            </a>
                        </th>
                        <th>Client / Site</th>
                        <th>
                            <a href="{{ $sortLink('due_at') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Due <i class="bi {{ $sortIcon('due_at') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('estimated_minutes') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Est. <i class="bi {{ $sortIcon('estimated_minutes') }}"></i>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tasks as $task)
                        <tr class="cursor-pointer" data-href="{{ route('tech.tasks.show', $task) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.tasks.show', $task) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $task->title }}
                                </a>
                                <div class="small text-muted">
                                    @if($task->children_count)
                                        {{ $task->children_count }} child tasks
                                    @endif
                                    @if($task->dependencies_count)
                                        <span class="ms-2">{{ $task->dependencies_count }} dependencies</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="badge {{ $task->status?->is_done ? 'text-bg-success' : ($task->status?->is_blocked ? 'text-bg-warning' : 'text-bg-light') }}">
                                    {{ $task->status?->name ?? '—' }}
                                </span>
                            </td>
                            <td class="{{ blank($task->assignee?->name) ? 'text-muted' : '' }}">{{ $missing($task->assignee?->name) }}</td>
                            <td class="{{ blank($task->queue?->name) ? 'text-muted' : '' }}">{{ $missing($task->queue?->name) }}</td>
                            <td class="{{ blank($task->priority?->name) ? 'text-muted' : '' }}">{{ $missing($task->priority?->name) }}</td>
                            <td>
                                @if($task->client)
                                    <div>{{ $task->client->name }}</div>
                                @elseif($task->workContext?->isInternal())
                                    <div><span class="badge text-bg-light border">Internal</span></div>
                                @else
                                    <div class="text-muted">Unscoped</div>
                                @endif
                                <div class="small {{ blank($task->site?->name) ? 'text-muted' : '' }}">{{ $missing($task->site?->name) }}</div>
                            </td>
                            <td class="{{ blank($task->due_at) ? 'text-muted' : '' }}">{{ $task->due_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td class="{{ blank($task->estimated_minutes) ? 'text-muted' : '' }}">
                                {{ $task->estimated_minutes ? $task->estimated_minutes.' min' : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No tasks found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($tasks->hasPages())
            <div class="card-footer">
                {{ $tasks->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

@section('rightbar')
    <!-- ------------------------------------------------- -->
    <!-- Documentation context -->
    <!-- ------------------------------------------------- -->
    <div class="accordion mb-3" id="taskDocsAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="taskDocsHeading">
                <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#taskDocsCollapse" aria-expanded="false" aria-controls="taskDocsCollapse">
                    <span class="fw-semibold">Documentation</span>
                </button>
            </h2>
            <div id="taskDocsCollapse" class="accordion-collapse collapse" aria-labelledby="taskDocsHeading" data-bs-parent="#taskDocsAccordion">
                <div class="accordion-body small">
                    <p class="mb-2">Tasks are internal work items that can belong to tickets, assets, clients, or a user.</p>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#taskDocumentationModal">
                        Open documentation
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Documentation modal -->
    <!-- ------------------------------------------------- -->
    <div class="modal fade" id="taskDocumentationModal" tabindex="-1" aria-labelledby="taskDocumentationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="taskDocumentationModalLabel">Task Documentation</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <p>Tasks are internal work items that can be assigned, sorted, nested, and tracked with time.</p>
                    <ul>
                        <li>Use child tasks when work needs its own assignee, status, queue, or time.</li>
                        <li>Use checklist items for small steps inside the same task.</li>
                        <li>Dependencies block task completion until required work is done.</li>
                        <li>Estimated minutes are used as time when a task is completed without actual time.</li>
                    </ul>
                    <a href="{{ route('tech.tasks.docs') }}" class="link-secondary" target="_blank">Open Markdown source</a>
                </div>
            </div>
        </div>
    </div>
@endsection
