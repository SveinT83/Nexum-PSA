{{--
    Reusable Task quick-create modal.

    The owning page passes the owner model so the Task module can create the
    task against that record and let StoreTask inherit known metadata.
--}}
@php
    $modalId ??= 'taskQuickCreateModal';
    $ownerModel ??= null;
    $assignees ??= collect();
    $timeRateOptions ??= collect();
    $defaultAssigneeId ??= null;
    $returnTo ??= url()->current();
    $isTicketOwner = $ownerModel instanceof \App\Modules\Ticket\Models\Ticket;
    $aiAssistAvailable = auth()->user()
        ? (bool) app(\App\Modules\Integration\Services\AiAgentResolver::class)->defaultAgent(auth()->user(), 'tasks')
        : false;
    $fullEditorUrl = $ownerModel
        ? route('tech.tasks.create', [
            'owner_type' => $ownerModel->getMorphClass(),
            'owner_id' => $ownerModel->getKey(),
        ])
        : route('tech.tasks.create');
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="{{ route('tech.tasks.store') }}" class="modal-content">
            @csrf
            @if($ownerModel)
                <input type="hidden" name="owner_type" value="{{ $ownerModel->getMorphClass() }}">
                <input type="hidden" name="owner_id" value="{{ $ownerModel->getKey() }}">
            @endif
            <input type="hidden" name="return_to" value="{{ $returnTo }}">

            <div class="modal-header">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h2 class="modal-title h5 mb-0" id="{{ $modalId }}Label">New Task</h2>
                    @if($isTicketOwner)
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary"
                            id="{{ $modalId }}AiAssist"
                            data-task-quick-ai-button
                            data-ai-suggest-url="{{ route('tech.tasks.ai-suggest') }}"
                            data-ticket-id="{{ $ownerModel->id }}"
                            data-ai-available="{{ $aiAssistAvailable ? '1' : '0' }}"
                            disabled>
                            <i class="bi bi-stars" aria-hidden="true"></i>
                            AI Assist
                        </button>
                    @endif
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="{{ $modalId }}Title">Title</label>
                    <input type="text" class="form-control" id="{{ $modalId }}Title" name="title" required>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="{{ $modalId }}Description">Description</label>
                    <textarea class="form-control" id="{{ $modalId }}Description" name="description" rows="3"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="{{ $modalId }}ChecklistText">Checklist</label>
                    <textarea class="form-control" id="{{ $modalId }}ChecklistText" name="checklist_text" rows="3" placeholder="One checklist item per line"></textarea>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="{{ $modalId }}AssignedTo">Assignee</label>
                        <select class="form-select" id="{{ $modalId }}AssignedTo" name="assigned_to">
                            <option value="">Unassigned</option>
                            @foreach($assignees as $assignee)
                                <option value="{{ $assignee->id }}" @selected((string) $defaultAssigneeId === (string) $assignee->id)>{{ $assignee->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="{{ $modalId }}DueAt">Due</label>
                        <input type="datetime-local" class="form-control" id="{{ $modalId }}DueAt" name="due_at">
                    </div>
                </div>

                @if($isTicketOwner)
                    <div class="border rounded bg-light p-3 mt-3">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="{{ $modalId }}EstimatedMinutes">Estimated minutes</label>
                                <input type="number" min="1" max="1440" class="form-control" id="{{ $modalId }}EstimatedMinutes" name="estimated_minutes" value="30">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="{{ $modalId }}TicketRateKey">Rate</label>
                                <select class="form-select" id="{{ $modalId }}TicketRateKey" name="ticket_rate_key" @disabled($timeRateOptions->isEmpty())>
                                    <option value="">Select rate</option>
                                    @foreach($timeRateOptions as $rateOption)
                                        <option value="{{ $rateOption['key'] }}">{{ $rateOption['label'] }} - {{ $rateOption['description'] }}</option>
                                    @endforeach
                                </select>
                                @if($timeRateOptions->isEmpty())
                                    <div class="form-text text-danger">No ticket time rates are available yet.</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="modal-footer d-flex justify-content-between">
                <a href="{{ $fullEditorUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" data-task-full-editor-link>
                    Open full editor
                </a>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Create Task</button>
                </div>
            </div>
        </form>
    </div>
</div>

@if($isTicketOwner)
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const button = document.getElementById(@json($modalId.'AiAssist'));
            const form = button?.closest('form');

            if (!button || !form || button.dataset.aiAvailable !== '1') {
                return;
            }

            const field = (name) => form.querySelector(`[name="${name}"]`);
            const value = (name) => field(name)?.value || '';
            const setValue = (name, nextValue) => {
                const input = field(name);

                if (!input || nextValue === undefined || nextValue === null) {
                    return;
                }

                input.value = nextValue;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            };
            const updateButton = () => {
                button.disabled = !(value('title').trim() && value('description').trim());
            };
            const updateFullEditorLink = () => {
                const link = form.querySelector('[data-task-full-editor-link]');

                if (!link) {
                    return;
                }

                const url = new URL(link.getAttribute('href'), window.location.origin);

                ['title', 'description', 'assigned_to', 'due_at', 'estimated_minutes', 'ticket_rate_key', 'checklist_text'].forEach((name) => {
                    const currentValue = value(name);

                    if (currentValue) {
                        url.searchParams.set(name, currentValue);
                    } else {
                        url.searchParams.delete(name);
                    }
                });

                link.setAttribute('href', url.toString());
            };

            form.addEventListener('input', updateButton);
            form.addEventListener('change', updateButton);
            form.addEventListener('input', updateFullEditorLink);
            form.addEventListener('change', updateFullEditorLink);
            updateButton();
            updateFullEditorLink();

            button.addEventListener('click', async () => {
                updateButton();

                if (button.disabled) {
                    return;
                }

                const originalHtml = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> AI Assist';

                try {
                    const response = await fetch(button.dataset.aiSuggestUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}',
                        },
                        body: JSON.stringify({
                            title: value('title'),
                            description: value('description'),
                            ticket_id: button.dataset.ticketId,
                            assigned_to: value('assigned_to') || null,
                            estimated_minutes: value('estimated_minutes') || null,
                            ticket_rate_key: value('ticket_rate_key') || null,
                            checklist_text: value('checklist_text') || null,
                        }),
                    });
                    const data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.message || 'AI assist failed.');
                    }

                    const suggestions = data.suggestions || {};

                    setValue('title', suggestions.title);
                    setValue('description', suggestions.description);
                    setValue('assigned_to', suggestions.assigned_to);
                    setValue('estimated_minutes', suggestions.estimated_minutes);
                    setValue('ticket_rate_key', suggestions.ticket_rate_key);

                    if (Array.isArray(suggestions.checklist_items)) {
                        setValue('checklist_text', suggestions.checklist_items.join("\n"));
                    }

                    updateFullEditorLink();
                } catch (error) {
                    alert(error.message);
                } finally {
                    button.innerHTML = originalHtml;
                    updateButton();
                }
            });
        });
    </script>
@endif
