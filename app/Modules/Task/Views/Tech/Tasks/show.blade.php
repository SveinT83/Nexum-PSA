{{--
    Task detail view

    Shows a compact internal task workspace with status, dependencies, checklist,
    children, time, and activity.
--}}
@extends('layouts.default_tech')

@php
    $isTicketTask = $task->owner instanceof \App\Modules\Ticket\Models\Ticket;
    $defaultInvoiceText = 'Task: '.$task->title;
    $defaultTicketRateKey = old('rate_key', $task->metadata['ticket_rate_key'] ?? ($timeRateOptions->first()['key'] ?? null));
@endphp

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">{{ $task->title }}</h1>
    </div>
    <div class="col-auto">
        <x-buttons.back url="{{ route('tech.tasks.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Task summary -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Task Details</span>
            <div class="d-flex align-items-center gap-2">
                <x-buttons.editlink url="{{ route('tech.tasks.edit', $task) }}" class="mb-0">Edit Task</x-buttons.editlink>
                @unless($isTicketTask)
                    <form method="post" action="{{ route('tech.tasks.complete', $task) }}" class="mb-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-success" @disabled((bool) $task->completed_at)>Complete</button>
                    </form>
                @endunless
            </div>
        </div>
        <div class="card-body">
            @if($task->description)
                <p>{{ $task->description }}</p>
            @endif

            <div class="row g-3 small">
                <div class="col-md-3">
                    <div class="text-muted">Status</div>
                    <div class="fw-semibold">{{ $task->status?->name ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted">Assignee</div>
                    <div class="fw-semibold">{{ $task->assignee?->name ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted">Queue</div>
                    <div class="fw-semibold">{{ $task->queue?->name ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted">Priority</div>
                    <div class="fw-semibold">{{ $task->priority?->name ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted">Client</div>
                    <div class="fw-semibold">
                        @if($task->client)
                            {{ $task->client->name }}
                        @elseif($task->workContext?->isInternal())
                            Internal
                        @else
                            —
                        @endif
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted">Site</div>
                    <div class="fw-semibold">{{ $task->site?->name ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted">Due</div>
                    <div class="fw-semibold">{{ $task->due_at?->format('Y-m-d H:i') ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted">Estimated / Actual</div>
                    <div class="fw-semibold">{{ $task->estimated_minutes ? $task->estimated_minutes.' min' : '—' }} / {{ $task->actual_minutes }} min</div>
                </div>
            </div>

            @if($task->tags->isNotEmpty())
                <div class="d-flex flex-wrap gap-1 mt-3">
                    @foreach($task->tags as $tag)
                        <span class="badge text-bg-light">{{ $tag->name }}</span>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="card-footer">
            <form method="post" action="{{ route('tech.tasks.status.update', $task) }}" class="row g-2 align-items-end">
                @csrf
                @method('PATCH')
                <div class="col-md-6">
                    <label class="form-label small text-muted" for="status_id">Change status</label>
                    <select class="form-select form-select-sm" id="status_id" name="status_id">
                        @foreach($statuses as $status)
                            <option value="{{ $status->id }}" @selected($task->status_id === $status->id)>{{ $status->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 text-md-end">
                    <button class="btn btn-sm btn-outline-primary" type="submit">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    @if($isTicketTask)
        <!-- ------------------------------------------------- -->
        <!-- Ticket billing completion -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span class="fw-semibold">Complete With Ticket Time</span>
                <span class="small text-muted">Creates ticket activity and pending billing/timebank entry</span>
            </div>
            <form method="post" action="{{ route('tech.tasks.complete', $task) }}">
                @csrf
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small text-muted" for="work_date">Work date</label>
                            <input type="date" class="form-control form-control-sm" id="work_date" name="work_date" value="{{ old('work_date', now()->toDateString()) }}" @disabled((bool) $task->completed_at)>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted" for="minutes">Minutes</label>
                            <input type="number" min="1" max="1440" class="form-control form-control-sm" id="minutes" name="minutes" value="{{ old('minutes', $task->estimated_minutes ?: 30) }}" @disabled((bool) $task->completed_at)>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted" for="rate_key">Rate</label>
                            <select class="form-select form-select-sm" id="rate_key" name="rate_key" @disabled($timeRateOptions->isEmpty() || (bool) $task->completed_at)>
                                <option value="">Select rate</option>
                                @foreach($timeRateOptions as $rateOption)
                                    <option value="{{ $rateOption['key'] }}" @selected($defaultTicketRateKey === $rateOption['key'])>
                                        {{ $rateOption['label'] }} - {{ $rateOption['description'] }}
                                    </option>
                                @endforeach
                            </select>
                            @if($timeRateOptions->isEmpty())
                                <div class="form-text text-danger">No available ticket time rates. Add a global or contract rate before completing this task.</div>
                            @endif
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small text-muted" for="invoice_text">Invoice text</label>
                            <textarea class="form-control form-control-sm" id="invoice_text" name="invoice_text" rows="2" @disabled((bool) $task->completed_at)>{{ old('invoice_text', $defaultInvoiceText) }}</textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted" for="note">Internal note</label>
                            <textarea class="form-control form-control-sm" id="note" name="note" rows="2" @disabled((bool) $task->completed_at)>{{ old('note') }}</textarea>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-sm btn-success" @disabled($timeRateOptions->isEmpty() || (bool) $task->completed_at)>Complete Task</button>
                </div>
            </form>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-6">
            <!-- ------------------------------------------------- -->
            <!-- Checklist -->
            <!-- ------------------------------------------------- -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Checklist</span>
                    <span class="small text-muted">{{ $task->checklistItems->where('is_checked', true)->count() }} / {{ $task->checklistItems->count() }}</span>
                </div>
                <div class="list-group list-group-flush">
                    @forelse($task->checklistItems as $item)
                        <form method="post" action="{{ route('tech.tasks.checklist.toggle', [$task, $item]) }}" class="list-group-item small">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-link btn-sm p-0 text-start text-decoration-none text-body d-flex align-items-center gap-2">
                                <i class="bi {{ $item->is_checked ? 'bi-check-square text-success' : 'bi-square text-muted' }}"></i>
                                <span class="{{ $item->is_checked ? 'text-decoration-line-through text-muted' : '' }}">{{ $item->title }}</span>
                            </button>
                        </form>
                    @empty
                        <div class="list-group-item text-muted small">No checklist items.</div>
                    @endforelse
                </div>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Child tasks -->
            <!-- ------------------------------------------------- -->
            <div class="card">
                <div class="card-header">
                    <span class="fw-semibold">Child Tasks</span>
                </div>
                <div class="list-group list-group-flush">
                    @forelse($task->children as $child)
                        <a href="{{ route('tech.tasks.show', $child) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span>{{ $child->title }}</span>
                            <span class="badge text-bg-light">{{ $child->status?->name ?? '—' }}</span>
                        </a>
                    @empty
                        <div class="list-group-item text-muted small">No child tasks.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <!-- ------------------------------------------------- -->
            <!-- Dependencies -->
            <!-- ------------------------------------------------- -->
            <div class="card mb-3">
                <div class="card-header">
                    <span class="fw-semibold">Dependencies</span>
                </div>
                <div class="list-group list-group-flush">
                    @forelse($task->dependencies as $dependency)
                        <a href="{{ route('tech.tasks.show', $dependency->dependsOnTask) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span>{{ $dependency->dependsOnTask?->title }}</span>
                            <span class="badge {{ $dependency->dependsOnTask?->completed_at ? 'text-bg-success' : 'text-bg-warning' }}">
                                {{ $dependency->dependsOnTask?->completed_at ? 'Done' : 'Blocking' }}
                            </span>
                        </a>
                    @empty
                        <div class="list-group-item text-muted small">No dependencies.</div>
                    @endforelse
                </div>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Activity -->
            <!-- ------------------------------------------------- -->
            <div class="card">
                <div class="card-header">
                    <span class="fw-semibold">Activity</span>
                </div>
                <div class="list-group list-group-flush">
                    @forelse($task->activities as $activity)
                        <div class="list-group-item small">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="fw-semibold">{{ $activity->user?->name ?? 'System' }}</span>
                                <span class="text-muted">{{ $activity->created_at?->format('Y-m-d H:i') }}</span>
                            </div>
                            <div>{{ $activity->body ?? $activity->type }}</div>
                        </div>
                    @empty
                        <div class="list-group-item text-muted small">No activity yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

@section('rightbar')
    <div class="accordion mb-3" id="taskDetailRightbar">
        <div class="accordion-item">
            <h2 class="accordion-header" id="taskStatusHeading">
                <button class="accordion-button py-2" type="button" data-bs-toggle="collapse" data-bs-target="#taskStatusCollapse" aria-expanded="true" aria-controls="taskStatusCollapse">
                    <span class="fw-semibold">Control</span>
                </button>
            </h2>
            <div id="taskStatusCollapse" class="accordion-collapse collapse show" aria-labelledby="taskStatusHeading" data-bs-parent="#taskDetailRightbar">
                <div class="accordion-body small">
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Owner</span>
                        <span>{{ class_basename($task->owner_type) }} #{{ $task->owner_id }}</span>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <span class="text-muted">Blocks owner</span>
                        <span>{{ $task->blocks_owner_completion ? 'Yes' : 'No' }}</span>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <span class="text-muted">Completed</span>
                        <span>{{ $task->completed_at?->format('Y-m-d H:i') ?? '—' }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="accordion-item">
            <h2 class="accordion-header" id="taskDocsHeading">
                <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#taskDocsCollapse" aria-expanded="false" aria-controls="taskDocsCollapse">
                    <span class="fw-semibold">Documentation</span>
                </button>
            </h2>
            <div id="taskDocsCollapse" class="accordion-collapse collapse" aria-labelledby="taskDocsHeading" data-bs-parent="#taskDetailRightbar">
                <div class="accordion-body small">
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
                        <li>Child tasks are real work items with their own status and assignee.</li>
                        <li>Checklist items are small steps inside this task.</li>
                        <li>Blocking dependencies must be completed before this task can be completed.</li>
                        <li>Standalone tasks can use the estimate as actual time when completed.</li>
                        <li>Ticket tasks register ticket time on completion so billing and timebank handling stays on the ticket.</li>
                    </ul>
                    <a href="{{ route('tech.tasks.docs') }}" class="link-secondary" target="_blank">Open Markdown source</a>
                </div>
            </div>
        </div>
    </div>
@endsection
