@extends('layouts.default_tech')

@section('title', $template->name)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">{{ $template->name }}</h1>
        <x-buttons.back :url="route('tech.admin.task-templates.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <!-- Template summary -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Template</span><a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.admin.task-templates.edit', $template) }}">Edit</a>
        </div>
        <div class="card-body"><p class="mb-2">{{ $template->description ?: 'No description.' }}</p><span class="badge {{ $template->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $template->is_active ? 'Active' : 'Inactive' }}</span><span class="small text-muted ms-2">Changes affect future Tasks only.</span></div>
    </div>

    <!-- Template Tasks -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Tasks ({{ $template->allItems->count() }})</h2></div>
        <div class="card-body">
            @php
                $expandedTemplateItemId = (int) session('expanded_template_item_id');
            @endphp
            <div class="accordion mb-3" id="template-task-accordion">
                @foreach($template->allItems as $item)
                    @php
                        $isExpanded = $expandedTemplateItemId === $item->id;
                    @endphp
                    <div class="accordion-item">
                        <h3 class="accordion-header" id="template-task-heading-{{ $item->id }}">
                            <button class="accordion-button py-2 {{ $isExpanded ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#template-task-collapse-{{ $item->id }}" aria-expanded="{{ $isExpanded ? 'true' : 'false' }}" aria-controls="template-task-collapse-{{ $item->id }}">
                                <span class="fw-semibold">{{ $item->title }}</span>
                                <span class="small text-muted ms-2">Order {{ $item->sort_order }}</span>
                            </button>
                        </h3>
                        <div id="template-task-collapse-{{ $item->id }}" class="accordion-collapse collapse{{ $isExpanded ? ' show' : '' }}" aria-labelledby="template-task-heading-{{ $item->id }}" data-bs-parent="#template-task-accordion">
                            <div class="accordion-body">
                                <form method="POST" action="{{ route('tech.admin.task-templates.items.update', [$template, $item]) }}">
                                    @csrf @method('PUT')
                                    <div class="row g-2">
                                        <div class="col-md-7"><label class="form-label small">Title</label><input class="form-control form-control-sm" name="title" required value="{{ $item->title }}"></div>
                                        <div class="col-md-2"><label class="form-label small">Order</label><input class="form-control form-control-sm" type="number" name="sort_order" value="{{ $item->sort_order }}"></div>
                                        <div class="col-md-3"><label class="form-label small">Parent</label><select class="form-select form-select-sm" name="parent_id"><option value="">None</option>@foreach($template->allItems->where('id', '!=', $item->id) as $candidate)<option value="{{ $candidate->id }}" @selected($item->parent_id === $candidate->id)>{{ $candidate->title }}</option>@endforeach</select></div>
                                        <div class="col-12"><label class="form-label small">Description</label><textarea class="form-control form-control-sm" name="description" rows="2">{{ $item->description }}</textarea></div>
                                        <div class="col-md-3"><label class="form-label small">Status</label><select class="form-select form-select-sm" name="status_id"><option value="">Task default</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected($item->status_id === $status->id)>{{ $status->name }}</option>@endforeach</select></div>
                                        <div class="col-md-3"><label class="form-label small">Queue</label><select class="form-select form-select-sm" name="queue_id"><option value="">No queue</option>@foreach($queues as $queue)<option value="{{ $queue->id }}" @selected($item->queue_id === $queue->id)>{{ $queue->name }}</option>@endforeach</select></div>
                                        <div class="col-md-3"><label class="form-label small">Priority</label><select class="form-select form-select-sm" name="priority_id"><option value="">No priority</option>@foreach($priorities as $priority)<option value="{{ $priority->id }}" @selected($item->priority_id === $priority->id)>{{ $priority->name }}</option>@endforeach</select></div>
                                        <div class="col-md-3"><label class="form-label small">Category</label><select class="form-select form-select-sm" name="category_id"><option value="">No category</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($item->category_id === $category->id)>{{ $category->name }}</option>@endforeach</select></div>
                                        <div class="col-md-3"><label class="form-label small">Assignee</label><select class="form-select form-select-sm" name="assigned_to"><option value="">Unassigned</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected($item->assigned_to === $user->id)>{{ $user->name }}</option>@endforeach</select></div>
                                        <div class="col-md-2"><label class="form-label small">Estimate min.</label><input class="form-control form-control-sm" type="number" name="estimated_minutes" value="{{ $item->estimated_minutes }}"></div>
                                        <div class="col-md-2"><label class="form-label small">Due offset min.</label><input class="form-control form-control-sm" type="number" name="due_offset_minutes" value="{{ $item->due_offset_minutes }}"></div>
                                        <div class="col-md-2"><label class="form-label small">Start offset min.</label><input class="form-control form-control-sm" type="number" name="scheduled_start_offset_minutes" value="{{ $item->scheduled_start_offset_minutes }}"></div>
                                        <div class="col-md-2"><label class="form-label small">End offset min.</label><input class="form-control form-control-sm" type="number" name="scheduled_end_offset_minutes" value="{{ $item->scheduled_end_offset_minutes }}"></div>
                                        <div class="col-md-3"><label class="form-label small">Tags (comma separated)</label><input class="form-control form-control-sm" name="tag_names" value="{{ $item->tags->pluck('name')->implode(', ') }}"></div>
                                        <div class="col-md-6"><label class="form-label small">Checklist (one per line)</label><textarea class="form-control form-control-sm" name="checklist_text" rows="3">{{ $item->checklistItems->pluck('title')->implode("\n") }}</textarea></div>
                                        <div class="col-md-6"><label class="form-label small">Depends on</label><select class="form-select form-select-sm" name="depends_on_ids[]" multiple size="3">@foreach($template->allItems->where('id', '!=', $item->id) as $candidate)<option value="{{ $candidate->id }}" @selected($item->dependencies->pluck('depends_on_template_item_id')->contains($candidate->id))>{{ $candidate->title }}</option>@endforeach</select></div>
                                        <div class="col-12 d-flex justify-content-between align-items-center"><div class="form-check"><input type="hidden" name="blocks_owner_completion" value="0"><input class="form-check-input" type="checkbox" name="blocks_owner_completion" value="1" id="blocks_{{ $item->id }}" @checked($item->blocks_owner_completion)><label class="form-check-label small" for="blocks_{{ $item->id }}">Blocks owner completion</label></div><div class="d-flex gap-2"><button class="btn btn-sm btn-primary" type="submit">Save Task</button><button class="btn btn-sm btn-outline-danger" type="submit" form="delete-item-{{ $item->id }}">Remove</button></div></div>
                                    </div>
                                </form>
                                <form id="delete-item-{{ $item->id }}" method="POST" action="{{ route('tech.admin.task-templates.items.destroy', [$template, $item]) }}">@csrf @method('DELETE')</form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('tech.admin.task-templates.items.store', $template) }}" class="border rounded p-3 bg-body-tertiary">
                @csrf
                <h3 class="h6">Add Task</h3>
                <div class="row g-2">
                    <div class="col-md-8"><label class="form-label small" for="add_task_title">Title</label><input class="form-control form-control-sm" id="add_task_title" name="title" required placeholder="e.g. Review backups for {client}"></div>
                    <div class="col-md-2"><label class="form-label small" for="add_task_estimated_minutes">Estimate min.</label><input class="form-control form-control-sm" id="add_task_estimated_minutes" type="number" name="estimated_minutes"></div>
                    <div class="col-md-2"><label class="form-label small" for="add_task_sort_order">Order</label><input class="form-control form-control-sm" id="add_task_sort_order" type="number" name="sort_order" value="{{ (($template->allItems->max('sort_order') ?? 0) + 10) }}"></div>
                    <div class="col-md-3"><label class="form-label small" for="add_task_status">Status</label><select class="form-select form-select-sm" id="add_task_status" name="status_id"><option value="">Task default status</option>@foreach($statuses as $status)<option value="{{ $status->id }}">{{ $status->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label small" for="add_task_queue">Queue</label><select class="form-select form-select-sm" id="add_task_queue" name="queue_id"><option value="">No queue</option>@foreach($queues as $queue)<option value="{{ $queue->id }}">{{ $queue->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label small" for="add_task_priority">Priority</label><select class="form-select form-select-sm" id="add_task_priority" name="priority_id"><option value="">No priority</option>@foreach($priorities as $priority)<option value="{{ $priority->id }}">{{ $priority->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label small" for="add_task_category">Category</label><select class="form-select form-select-sm" id="add_task_category" name="category_id"><option value="">No category</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label small" for="add_task_checklist">Checklist (one item per line)</label><textarea class="form-control form-control-sm" id="add_task_checklist" name="checklist_text" rows="2"></textarea></div>
                    <div class="col-12 text-end"><button class="btn btn-sm btn-primary" type="submit">Add Task</button></div>
                </div>
            </form>
        </div>
    </div>

    <!-- Manual application -->
    <datalist id="task-template-owner-users">
        @foreach($users as $user)
            <option value="{{ $user->name }}{{ $user->email ? ' — '.$user->email : '' }}" data-id="{{ $user->id }}"></option>
        @endforeach
    </datalist>
    <datalist id="task-template-owner-clients">
        @foreach($clients as $client)
            <option value="{{ $client->name }}{{ $client->client_number ? ' ('.$client->client_number.')' : '' }}" data-id="{{ $client->id }}"></option>
        @endforeach
    </datalist>
    <div class="row g-3 mb-3">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h2 class="h6 mb-0">Apply template</h2></div><div class="card-body"><form method="GET" action="{{ route('tech.task-templates.preview', $template) }}"><div class="row g-2" data-owner-picker><div class="col-md-4"><label class="form-label small" for="apply_owner_type">Create Tasks for</label><select class="form-select form-select-sm" id="apply_owner_type" name="owner_type" data-owner-type><option value="user">User</option><option value="client">Client</option></select></div><div class="col-md-5"><label class="form-label small" for="apply_owner_lookup">Select User or Client</label><input class="form-control form-control-sm" id="apply_owner_lookup" type="search" value="{{ auth()->user()?->name }}{{ auth()->user()?->email ? ' — '.auth()->user()->email : '' }}" autocomplete="off" required data-owner-lookup><input type="hidden" name="owner_id" value="{{ auth()->id() }}" data-owner-id></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Preview</button></div></div></form></div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h2 class="h6 mb-0">Recurring schedule</h2></div><div class="card-body"><form method="POST" action="{{ route('tech.admin.task-templates.schedules.store', $template) }}">@csrf<div class="row g-2" data-owner-picker><div class="col-md-6"><label class="form-label small" for="new_schedule_name">Schedule name</label><input class="form-control form-control-sm" id="new_schedule_name" name="schedule_name" required></div><div class="col-md-3"><label class="form-label small" for="new_schedule_owner_type">Create Tasks for</label><select class="form-select form-select-sm" id="new_schedule_owner_type" name="owner_type" data-owner-type><option value="user">User</option><option value="client">Client</option></select></div><div class="col-md-3"><label class="form-label small" for="new_schedule_owner_lookup">Select User or Client</label><input class="form-control form-control-sm" id="new_schedule_owner_lookup" type="search" value="{{ auth()->user()?->name }}{{ auth()->user()?->email ? ' — '.auth()->user()->email : '' }}" autocomplete="off" required data-owner-lookup><input type="hidden" name="owner_id" value="{{ auth()->id() }}" data-owner-id></div><div class="col-md-4"><label class="form-label small" for="new_schedule_interval">Frequency</label><select class="form-select form-select-sm" id="new_schedule_interval" name="interval"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly" selected>Monthly</option><option value="quarterly">Quarterly</option></select></div><div class="col-md-4"><label class="form-label small" for="new_schedule_next_run">First run</label><input class="form-control form-control-sm" id="new_schedule_next_run" type="datetime-local" name="next_run_at" required></div><div class="col-md-4"><label class="form-label small" for="new_schedule_timezone">Timezone</label><input class="form-control form-control-sm" id="new_schedule_timezone" name="timezone" value="Europe/Oslo" required></div><div class="col-md-4"><label class="form-label small" for="new_schedule_due_offset">Due offset min.</label><input class="form-control form-control-sm" id="new_schedule_due_offset" type="number" name="due_offset_minutes"></div><div class="col-md-5"><label class="form-label small" for="new_schedule_assignee">Assignee</label><select class="form-select form-select-sm" id="new_schedule_assignee" name="assigned_to"><option value="">Template assignee</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100">Add schedule</button></div></div></form></div></div></div>
    </div>

    <!-- Schedules and history -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Schedules</h2></div>
        <div class="card-body">
            @forelse($template->recurringTemplates as $schedule)
                @php
                    $scheduleOwnerType = $schedule->owner instanceof \App\Models\Clients\Client ? 'client' : 'user';
                    $scheduleOwnerLabel = $scheduleOwnerType === 'client'
                        ? $schedule->owner?->name.($schedule->owner?->client_number ? ' ('.$schedule->owner->client_number.')' : '')
                        : $schedule->owner?->name.($schedule->owner?->email ? ' — '.$schedule->owner->email : '');
                @endphp
                <div class="border rounded p-3 mb-3">
                    <form method="POST" action="{{ route('tech.admin.task-templates.schedules.update', [$template, $schedule]) }}">
                        @csrf @method('PUT')
                        <div class="row g-2" data-owner-picker>
                            <div class="col-md-4"><label class="form-label small">Name</label><input class="form-control form-control-sm" name="schedule_name" value="{{ $schedule->name }}" required></div>
                            <div class="col-md-2"><label class="form-label small">Frequency</label><select class="form-select form-select-sm" name="interval">@foreach(['daily', 'weekly', 'monthly', 'quarterly'] as $interval)<option value="{{ $interval }}" @selected($schedule->interval === $interval)>{{ ucfirst($interval) }}</option>@endforeach</select></div>
                            <div class="col-md-3"><label class="form-label small">Next run</label><input class="form-control form-control-sm" type="datetime-local" name="next_run_at" value="{{ $schedule->next_run_at?->timezone($schedule->timezone)->format('Y-m-d\TH:i') }}" required></div>
                            <div class="col-md-3"><label class="form-label small">Timezone</label><input class="form-control form-control-sm" name="timezone" value="{{ $schedule->timezone }}" required></div>
                            <div class="col-md-2"><label class="form-label small" for="schedule_owner_type_{{ $schedule->id }}">Create Tasks for</label><select class="form-select form-select-sm" id="schedule_owner_type_{{ $schedule->id }}" name="owner_type" data-owner-type>@foreach(['user', 'client'] as $ownerType)<option value="{{ $ownerType }}" @selected($scheduleOwnerType === $ownerType)>{{ ucfirst($ownerType) }}</option>@endforeach</select></div>
                            <div class="col-md-4"><label class="form-label small" for="schedule_owner_lookup_{{ $schedule->id }}">Select User or Client</label><input class="form-control form-control-sm" id="schedule_owner_lookup_{{ $schedule->id }}" type="search" value="{{ $scheduleOwnerLabel }}" autocomplete="off" required data-owner-lookup><input type="hidden" name="owner_id" value="{{ $schedule->owner_id }}" data-owner-id></div>
                            <div class="col-md-3"><label class="form-label small">Assignee</label><select class="form-select form-select-sm" name="assigned_to"><option value="">Template default</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected($schedule->assigned_to === $user->id)>{{ $user->name }}</option>@endforeach</select></div>
                            <div class="col-md-2"><label class="form-label small">Due offset min.</label><input class="form-control form-control-sm" type="number" name="due_offset_minutes" value="{{ $schedule->due_offset_minutes }}"></div>
                            <div class="col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100">Save schedule</button></div>
                        </div>
                    </form>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
                        <span class="small text-muted"><span class="badge {{ $schedule->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $schedule->is_active ? 'Active' : 'Inactive' }}</span> Last result: {{ $schedule->last_result ?? 'Never' }}</span>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('tech.admin.task-templates.schedules.run', [$template, $schedule]) }}">@csrf<button class="btn btn-sm btn-outline-primary" @disabled(!$schedule->is_active || !$template->is_active)>Generate now</button></form>
                            <form method="POST" action="{{ route('tech.admin.task-templates.schedules.toggle', [$template, $schedule]) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-secondary">{{ $schedule->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                            <form method="POST" action="{{ route('tech.admin.task-templates.schedules.destroy', [$template, $schedule]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-muted mb-0">No schedules.</p>
            @endforelse
        </div>
    </div>
    <div class="card"><div class="card-header"><h2 class="h6 mb-0">Recent generation history</h2></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>When</th><th>Source</th><th>Status</th><th>Tasks</th><th>Reason</th></tr></thead><tbody>@forelse($template->runs as $run)<tr><td>{{ $run->created_at?->format('Y-m-d H:i') }}</td><td>{{ str_replace('_', ' ', ucfirst($run->trigger_type)) }}</td><td>{{ ucfirst($run->status) }}</td><td>{{ $run->task_count }}</td><td class="text-muted">{{ $run->failure_reason ?: '—' }}</td></tr>@empty<tr><td colspan="5" class="text-muted text-center">No generation runs.</td></tr>@endforelse</tbody></table></div></div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const listIds = {user: 'task-template-owner-users', client: 'task-template-owner-clients'};

            document.querySelectorAll('[data-owner-picker]').forEach((picker) => {
                const type = picker.querySelector('[data-owner-type]');
                const lookup = picker.querySelector('[data-owner-lookup]');
                const ownerId = picker.querySelector('[data-owner-id]');

                const useCurrentList = (clearSelection = false) => {
                    lookup.setAttribute('list', listIds[type.value]);
                    lookup.placeholder = type.value === 'client' ? 'Start typing a Client name' : 'Start typing a User name';
                    if (clearSelection) {
                        lookup.value = '';
                        ownerId.value = '';
                    }
                };
                const syncSelection = () => {
                    const list = document.getElementById(listIds[type.value]);
                    const option = [...list.options].find((candidate) => candidate.value === lookup.value);
                    ownerId.value = option?.dataset.id ?? '';
                    lookup.setCustomValidity(ownerId.value ? '' : 'Select a value from the list.');
                };

                type.addEventListener('change', () => useCurrentList(true));
                lookup.addEventListener('input', syncSelection);
                lookup.addEventListener('change', syncSelection);
                picker.closest('form')?.addEventListener('submit', (event) => {
                    syncSelection();
                    if (! ownerId.value) {
                        event.preventDefault();
                        lookup.reportValidity();
                    }
                });
                useCurrentList();
            });
        });
    </script>
@endsection
