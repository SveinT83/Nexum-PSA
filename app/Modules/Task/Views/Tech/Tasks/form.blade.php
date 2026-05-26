{{--
    Task create/edit form

    Creates or edits a standalone task. Other domains can later call the
    StoreTask action directly with their own owner model.
--}}
@extends('layouts.default_tech')

@php
    $task ??= null;
    $ownerContext ??= null;
    $prefill ??= [];
    $isEdit = filled($task);
    $formAction = $isEdit ? route('tech.tasks.update', $task) : route('tech.tasks.store');
    $checklistText = old('checklist_text', $isEdit ? $task->checklistItems->pluck('title')->implode("\n") : ($prefill['checklist_text'] ?? ''));
    $selectedTags = old('tag_names', $isEdit ? $task->tags->pluck('name')->all() : ($prefill['tag_names'] ?? []));
    $dateValue = fn ($field) => old($field, $isEdit ? $task->{$field}?->format('Y-m-d\TH:i') : ($prefill[$field] ?? null));
@endphp

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">{{ $isEdit ? 'Edit Task' : 'Create Task' }}</h1>
    </div>
    <div class="col-auto">
        <x-buttons.back url="{{ $isEdit ? route('tech.tasks.show', $task) : route('tech.tasks.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <form method="post" action="{{ $formAction }}">
        @csrf
        @if($isEdit)
            @method('PATCH')
        @endif
        @if($ownerContext && ! $ownerContext instanceof \App\Modules\Ticket\Models\Ticket)
            <input type="hidden" name="owner_type" value="{{ $ownerContext->getMorphClass() }}">
            <input type="hidden" name="owner_id" value="{{ $ownerContext->getKey() }}">
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Core task fields -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="fw-semibold">Task</span>
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        id="taskAiAssistButton"
                        data-ai-suggest-url="{{ route('tech.tasks.ai-suggest') }}"
                        data-ai-available="{{ ($aiAssistAvailable ?? false) ? '1' : '0' }}"
                        disabled>
                        <i class="bi bi-stars" aria-hidden="true"></i>
                        AI Assist
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="title">Title</label>
                        <input type="text" class="form-control" id="title" name="title" value="{{ old('title', $task?->title ?? ($prefill['title'] ?? null)) }}" required data-task-ai-required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="assigned_to">Assignee</label>
                        <select class="form-select" id="assigned_to" name="assigned_to">
                            <option value="">Unassigned</option>
                            @foreach($users as $user)
                                <option value="{{ $user->id }}" @selected((string) old('assigned_to', $task?->assigned_to ?? ($prefill['assigned_to'] ?? null)) === (string) $user->id)>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4" data-task-ai-required>{{ old('description', $task?->description ?? ($prefill['description'] ?? null)) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Client, site, ticket, and ticket billing intent -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">
                <span class="fw-semibold">Context</span>
            </div>
            <div class="card-body">
                <livewire:tech.tasks.form-context :task="$task" :owner-context="$ownerContext" :prefill="$prefill" />
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Workflow and schedule -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">
                <span class="fw-semibold">Workflow & Schedule</span>
            </div>
            <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="status_id">Status</label>
                    <select class="form-select" id="status_id" name="status_id">
                        @foreach($statuses as $status)
                            <option value="{{ $status->id }}" @selected((string) old('status_id', $task?->status_id) === (string) $status->id || (! $isEdit && $status->is_default && ! old('status_id')))>{{ $status->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="queue_id">Queue</label>
                    <select class="form-select" id="queue_id" name="queue_id">
                        <option value="">No queue</option>
                        @foreach($queues as $queue)
                            <option value="{{ $queue->id }}" @selected((string) old('queue_id', $task?->queue_id ?? ($prefill['queue_id'] ?? null)) === (string) $queue->id)>{{ $queue->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="priority_id">Priority</label>
                    <select class="form-select" id="priority_id" name="priority_id">
                        <option value="">No priority</option>
                        @foreach($priorities as $priority)
                            <option value="{{ $priority->id }}" @selected((string) old('priority_id', $task?->priority_id ?? ($prefill['priority_id'] ?? null)) === (string) $priority->id)>{{ $priority->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="category_id">Category</label>
                    <select class="form-select" id="category_id" name="category_id">
                        <option value="">No category</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('category_id', $task?->category_id ?? ($prefill['category_id'] ?? null)) === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="due_at">Due</label>
                    <input type="datetime-local" class="form-control" id="due_at" name="due_at" value="{{ $dateValue('due_at') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="scheduled_start_at">Scheduled start</label>
                    <input type="datetime-local" class="form-control" id="scheduled_start_at" name="scheduled_start_at" value="{{ $dateValue('scheduled_start_at') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="scheduled_end_at">Scheduled end</label>
                    <input type="datetime-local" class="form-control" id="scheduled_end_at" name="scheduled_end_at" value="{{ $dateValue('scheduled_end_at') }}">
                </div>
            </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Taxonomy and completion control -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">
                <span class="fw-semibold">Classification</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="tags">Tags</label>
                    <div class="task-tag-input form-control d-flex flex-wrap align-items-center gap-1 p-1" data-task-tag-input>
                        @foreach($selectedTags as $tagName)
                            @continue(blank($tagName))
                            <span class="badge text-bg-secondary d-inline-flex align-items-center gap-1" data-tag-chip="{{ $tagName }}">
                                {{ $tagName }}
                                <button type="button" class="btn-close btn-close-white" data-remove-tag aria-label="Remove {{ $tagName }}"></button>
                                <input type="hidden" name="tag_names[]" value="{{ $tagName }}">
                            </span>
                        @endforeach
                        <input type="text" class="task-tag-input__field border-0 flex-grow-1 px-1" list="taskTagSuggestions" placeholder="Add tag">
                    </div>
                    <datalist id="taskTagSuggestions">
                        @foreach($tags as $tag)
                            <option value="{{ $tag->name }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <div class="col-md-4">
                    <div class="form-check mt-4">
                        <input type="checkbox" class="form-check-input" id="blocks_owner_completion" name="blocks_owner_completion" value="1" @checked(old('blocks_owner_completion', $task?->blocks_owner_completion))>
                        <label class="form-check-label" for="blocks_owner_completion">Block owner completion</label>
                    </div>
                </div>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Checklist -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">
                <span class="fw-semibold">Checklist</span>
            </div>
            <div class="card-body">
                <livewire:tech.tasks.checklist-editor :initial-items="$checklistText" />
            </div>
        </div>

        <div class="text-end">
            <button type="submit" class="btn btn-primary btn-sm">{{ $isEdit ? 'Save Task' : 'Create Task' }}</button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

@section('rightbar')
    <!-- ------------------------------------------------- -->
    <!-- Documentation context -->
    <!-- ------------------------------------------------- -->
    <div class="accordion mb-3" id="taskFormRightbar">
        <div class="accordion-item">
            <h2 class="accordion-header" id="taskFormDocsHeading">
                <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#taskFormDocsCollapse" aria-expanded="false" aria-controls="taskFormDocsCollapse">
                    <span class="fw-semibold">Documentation</span>
                </button>
            </h2>
            <div id="taskFormDocsCollapse" class="accordion-collapse collapse" aria-labelledby="taskFormDocsHeading" data-bs-parent="#taskFormRightbar">
                <div class="accordion-body small">
                    <p class="mb-2">Use tasks for internal work that needs assignment, status, time, checklist, or dependencies.</p>
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
                    <h2 class="modal-title h5" id="taskDocumentationModalLabel">Task Fields</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <p>Title should describe the expected outcome. Use description for context, links, and acceptance notes.</p>
                    <ul>
                        <li>Assignee is the person expected to do the work.</li>
                        <li>Queue groups the task by operational area.</li>
                        <li>Priority controls urgency.</li>
                        <li>Tags are typed as chips; existing tags are suggested, new names are created on save.</li>
                        <li>Estimated minutes are used when completing a task without actual time.</li>
                        <li>Checklist is for small steps that do not need separate ownership or time.</li>
                    </ul>
                    <a href="{{ route('tech.tasks.docs') }}" class="link-secondary" target="_blank">Open Markdown source</a>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const normalizeTag = (value) => value.trim().replace(/\s+/g, ' ');

            document.querySelectorAll('[data-task-tag-input]').forEach((container) => {
                const input = container.querySelector('.task-tag-input__field');

                const existingNames = () => Array.from(container.querySelectorAll('input[name="tag_names[]"]'))
                    .map((hidden) => hidden.value.toLowerCase());

                const addTag = (rawName) => {
                    const name = normalizeTag(rawName);

                    if (!name || existingNames().includes(name.toLowerCase())) {
                        input.value = '';
                        return;
                    }

                    const chip = document.createElement('span');
                    chip.className = 'badge text-bg-secondary d-inline-flex align-items-center gap-1';
                    chip.dataset.tagChip = name;
                    chip.append(document.createTextNode(name));

                    const removeButton = document.createElement('button');
                    removeButton.type = 'button';
                    removeButton.className = 'btn-close btn-close-white';
                    removeButton.dataset.removeTag = '';
                    removeButton.setAttribute('aria-label', `Remove ${name}`);

                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'tag_names[]';
                    hidden.value = name;

                    chip.append(removeButton, hidden);
                    container.insertBefore(chip, input);
                    input.value = '';
                };

                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ',') {
                        event.preventDefault();
                        addTag(input.value);
                    }

                    if (event.key === 'Backspace' && input.value === '') {
                        const chips = container.querySelectorAll('[data-tag-chip]');
                        chips[chips.length - 1]?.remove();
                    }
                });

                input.addEventListener('change', () => addTag(input.value));
                input.closest('form')?.addEventListener('submit', () => addTag(input.value));

                container.addEventListener('click', (event) => {
                    if (event.target.matches('[data-remove-tag]')) {
                        event.preventDefault();
                        event.target.closest('[data-tag-chip]')?.remove();
                        return;
                    }

                    input.focus();
                });
            });

            const form = document.querySelector('form[action="{{ $formAction }}"]');
            const aiButton = document.getElementById('taskAiAssistButton');

            if (!form || !aiButton || aiButton.dataset.aiAvailable !== '1') {
                return;
            }

            const fieldValue = (name) => form.querySelector(`[name="${name}"]`)?.value || '';
            const hasContext = () => ['client_id', 'site_id', 'owner_id', 'parent_id'].some((name) => fieldValue(name));
            const updateAiButton = () => {
                aiButton.disabled = !(fieldValue('title').trim() && fieldValue('description').trim() && hasContext());
            };
            const setField = (name, value) => {
                const field = form.querySelector(`[name="${name}"]`);
                if (!field || value === undefined || value === null) {
                    return;
                }

                field.value = value;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            };
            const currentTags = () => Array.from(form.querySelectorAll('input[name="tag_names[]"]')).map((input) => input.value);
            const collectPayload = () => ({
                title: fieldValue('title'),
                description: fieldValue('description'),
                client_id: fieldValue('client_id') || null,
                site_id: fieldValue('site_id') || null,
                ticket_id: fieldValue('owner_id') || null,
                parent_id: fieldValue('parent_id') || null,
                queue_id: fieldValue('queue_id') || null,
                priority_id: fieldValue('priority_id') || null,
                category_id: fieldValue('category_id') || null,
                assigned_to: fieldValue('assigned_to') || null,
                estimated_minutes: fieldValue('estimated_minutes') || null,
                ticket_rate_key: fieldValue('ticket_rate_key') || null,
                tag_names: currentTags(),
                checklist_text: fieldValue('checklist_text') || null,
            });

            form.addEventListener('input', updateAiButton);
            form.addEventListener('change', updateAiButton);
            updateAiButton();

            aiButton.addEventListener('click', async () => {
                updateAiButton();

                if (aiButton.disabled) {
                    return;
                }

                const originalHtml = aiButton.innerHTML;
                aiButton.disabled = true;
                aiButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> AI Assist';

                try {
                    const response = await fetch(aiButton.dataset.aiSuggestUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}',
                        },
                        body: JSON.stringify(collectPayload()),
                    });
                    const data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.message || 'AI assist failed.');
                    }

                    const suggestions = data.suggestions || {};

                    setField('title', suggestions.title);
                    setField('description', suggestions.description);
                    setField('queue_id', suggestions.queue_id);
                    setField('priority_id', suggestions.priority_id);
                    setField('category_id', suggestions.category_id);
                    setField('assigned_to', suggestions.assigned_to);

                    if (Array.isArray(suggestions.tag_names)) {
                        suggestions.tag_names.forEach((tagName) => {
                            document.querySelector('[data-task-tag-input] .task-tag-input__field').value = tagName;
                            document.querySelector('[data-task-tag-input] .task-tag-input__field').dispatchEvent(new Event('change', { bubbles: true }));
                        });
                    }

                    if (window.Livewire) {
                        window.Livewire.dispatch('task-ai-context-suggested', { suggestions });

                        if (Array.isArray(suggestions.checklist_items)) {
                            window.Livewire.dispatch('task-ai-checklist-suggested', { items: suggestions.checklist_items });
                        }
                    }
                } catch (error) {
                    alert(error.message);
                } finally {
                    aiButton.innerHTML = originalHtml;
                    updateAiButton();
                }
            });
        });
    </script>
@endsection
