{{--
    Reusable Task quick-view modal.

    This keeps lightweight task work inside the owning page while preserving a
    clear escape hatch to the full Task workspace.
--}}
@php
    $modalId ??= 'taskQuickViewModal'.$task->id;
    $assignees ??= collect();
    $timeRateOptions ??= collect();
    $ticketMorphClass = (new \App\Modules\Ticket\Models\Ticket())->getMorphClass();
    $isTicketTask = in_array($task->owner_type, [\App\Modules\Ticket\Models\Ticket::class, $ticketMorphClass], true);
    $defaultInvoiceText = 'Task: '.$task->title;
    $defaultTicketRateKey = $task->metadata['ticket_rate_key'] ?? null;
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}Label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="min-w-0">
                    <h2 class="modal-title h5 text-truncate" id="{{ $modalId }}Label">{{ $task->title }}</h2>
                    <div class="small text-muted">
                        {{ $task->status?->name ?? 'Open' }}
                        @if($task->due_at)
                            <span class="ms-2">Due {{ $task->due_at->format('Y-m-d H:i') }}</span>
                        @endif
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                @if(filled($task->description))
                    <div class="mb-3" style="white-space: pre-wrap;">{{ $task->description }}</div>
                @endif

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <form method="post" action="{{ route('tech.tasks.assign', $task) }}" class="border rounded bg-light p-2">
                            @csrf
                            @method('PATCH')
                            <label class="form-label small text-muted" for="{{ $modalId }}AssignedTo">Assignee</label>
                            <div class="input-group input-group-sm">
                                <select class="form-select" id="{{ $modalId }}AssignedTo" name="assigned_to">
                                    <option value="">Unassigned</option>
                                    @foreach($assignees as $assignee)
                                        <option value="{{ $assignee->id }}" @selected((string) $task->assigned_to === (string) $assignee->id)>{{ $assignee->name }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-outline-primary">Save</button>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded bg-light p-2 h-100 small">
                            <div class="text-muted text-uppercase" style="font-size: .68rem;">Estimated / actual</div>
                            <div class="fw-semibold">{{ $task->estimated_minutes ? $task->estimated_minutes.' min' : '-' }} / {{ $task->actual_minutes }} min</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center py-2">
                        <span class="fw-semibold">Checklist</span>
                        <span class="small text-muted" data-task-checklist-count>{{ $task->checklistItems->where('is_checked', true)->count() }} / {{ $task->checklistItems->count() }}</span>
                    </div>
                    <div class="list-group list-group-flush">
                        @forelse($task->checklistItems as $item)
                            <form method="post" action="{{ route('tech.tasks.checklist.toggle', [$task, $item]) }}" class="list-group-item small" data-task-checklist-toggle-form>
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-link btn-sm p-0 text-start text-decoration-none text-body d-flex align-items-center gap-2" data-task-checklist-toggle-button>
                                    <i class="bi {{ $item->is_checked ? 'bi-check-square text-success' : 'bi-square text-muted' }}" data-task-checklist-icon></i>
                                    <span class="{{ $item->is_checked ? 'text-decoration-line-through text-muted' : '' }}" data-task-checklist-title>{{ $item->title }}</span>
                                </button>
                            </form>
                        @empty
                            <div class="list-group-item text-muted small">No checklist items.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="modal-footer d-flex justify-content-between align-items-start">
                <a href="{{ route('tech.tasks.show', $task) }}" class="btn btn-sm btn-outline-secondary">
                    View in Task workspace
                </a>
                <form method="post" action="{{ route('tech.tasks.complete', $task) }}" class="mb-0 {{ $isTicketTask ? 'flex-grow-1 ms-3' : '' }}">
                    @csrf
                    @if($isTicketTask)
                        <div class="border rounded bg-light p-2">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label small text-muted" for="{{ $modalId }}WorkDate">Work date</label>
                                    <input type="date" class="form-control form-control-sm" id="{{ $modalId }}WorkDate" name="work_date" value="{{ now()->toDateString() }}" @disabled((bool) $task->completed_at)>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small text-muted" for="{{ $modalId }}Minutes">Minutes</label>
                                    <input type="number" min="1" max="1440" class="form-control form-control-sm" id="{{ $modalId }}Minutes" name="minutes" value="{{ $task->estimated_minutes ?: 30 }}" @disabled((bool) $task->completed_at)>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted" for="{{ $modalId }}RateKey">Rate</label>
                                    <select class="form-select form-select-sm" id="{{ $modalId }}RateKey" name="rate_key" @disabled($timeRateOptions->isEmpty() || (bool) $task->completed_at)>
                                        <option value="">Select rate</option>
                                        @foreach($timeRateOptions as $rateOption)
                                            <option value="{{ $rateOption['key'] }}" @selected($defaultTicketRateKey === $rateOption['key'])>{{ $rateOption['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3 text-end">
                                    <button type="submit" class="btn btn-sm btn-success" @disabled($timeRateOptions->isEmpty() || (bool) $task->completed_at)>Complete</button>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small text-muted" for="{{ $modalId }}InvoiceText">Invoice text</label>
                                    <input type="text" class="form-control form-control-sm" id="{{ $modalId }}InvoiceText" name="invoice_text" value="{{ $defaultInvoiceText }}" @disabled((bool) $task->completed_at)>
                                    @if($timeRateOptions->isEmpty())
                                        <div class="form-text text-danger">Open the full Task workspace after adding a ticket time rate.</div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @else
                        <button type="submit" class="btn btn-sm btn-success" @disabled((bool) $task->completed_at)>Complete</button>
                    @endif
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        if (window.taskQuickChecklistToggleBound) {
            return;
        }

        window.taskQuickChecklistToggleBound = true;

        document.addEventListener('submit', async (event) => {
            const form = event.target.closest('[data-task-checklist-toggle-form]');

            if (!form) {
                return;
            }

            event.preventDefault();

            const button = form.querySelector('[data-task-checklist-toggle-button]');
            const icon = form.querySelector('[data-task-checklist-icon]');
            const title = form.querySelector('[data-task-checklist-title]');
            const modal = form.closest('.modal');
            const count = modal?.querySelector('[data-task-checklist-count]');

            button?.setAttribute('disabled', 'disabled');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                });

                if (!response.ok && !response.redirected) {
                    throw new Error('Checklist update failed.');
                }

                const isChecked = icon?.classList.contains('bi-square');

                icon?.classList.toggle('bi-square', !isChecked);
                icon?.classList.toggle('bi-check-square', isChecked);
                icon?.classList.toggle('text-muted', !isChecked);
                icon?.classList.toggle('text-success', isChecked);
                title?.classList.toggle('text-decoration-line-through', isChecked);
                title?.classList.toggle('text-muted', isChecked);

                if (count) {
                    const [checked, total] = count.textContent.split('/').map((part) => parseInt(part.trim(), 10));
                    const nextChecked = Math.max(0, checked + (isChecked ? 1 : -1));
                    count.textContent = `${nextChecked} / ${total}`;
                }
            } catch (error) {
                alert(error.message);
            } finally {
                button?.removeAttribute('disabled');
            }
        });
    });
</script>
