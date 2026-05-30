@extends('layouts.default_tech')

@section('title', 'Tickets')

@section('pageName')
    <h3>Tickets</h3>
@endsection

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Tickets</h1>
        <x-buttons.back url="{{ route('tech.dashboard') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
<div class="container-fluid px-0">
    @php
        $sort = $filters['sort'] ?? 'newest';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sortLink = function (string $column, string $defaultDirection = 'asc') use ($sort, $direction) {
            $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : ($sort === $column ? 'asc' : $defaultDirection);

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
    @endphp

    <style>
        .ticket-row-active-timer > * {
            --bs-table-bg: #eaf4ff;
            --bs-table-striped-bg: #eaf4ff;
            --bs-table-hover-bg: #dceeff;
        }
    </style>

    {{-- Main pane: keep the ticket index focused on the list; filters and stats live in the side rails. --}}
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Ticket Search</h2>
            <x-buttons.addlink url="{{ route('tech.tickets.create') }}" class="mb-0">New ticket</x-buttons.addlink>
        </div>
        <div class="card-body">
            @php
                $ticketFiltersCollapseId = 'ticketIndexFiltersCollapse';
                $ticketActiveFilterCount = collect([
                    ($filters['sort'] ?? 'newest') !== 'newest',
                    filled($filters['status_id'] ?? null),
                    filled($filters['priority_id'] ?? null),
                    filled($filters['category_id'] ?? null),
                    filled($filters['queue_id'] ?? null),
                    ($filters['lifecycle'] ?? 'open') !== 'open',
                    ($filters['ownership'] ?? 'mine_unassigned') !== 'mine_unassigned',
                    ! empty($filters['unread']),
                    ! empty($filters['unassigned']),
                ])->filter()->count();
                $ticketFiltersOpen = $ticketActiveFilterCount > 0;
            @endphp
            <form method="GET" action="{{ route('tech.tickets.index') }}">
                <input type="hidden" name="client_id" value="{{ $filters['client_id'] ?? '' }}">
                <input type="hidden" name="direction" value="{{ $filters['direction'] ?? 'desc' }}">

                <label for="ticket_index_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
                <div class="input-group input-group-sm">
                    <input id="ticket_index_search" name="q" type="search" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="Ticket key, subject, or description">
                    <button class="btn btn-outline-secondary" type="submit">Search</button>
                    <button
                        class="btn btn-outline-secondary"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#{{ $ticketFiltersCollapseId }}"
                        aria-expanded="{{ $ticketFiltersOpen ? 'true' : 'false' }}"
                        aria-controls="{{ $ticketFiltersCollapseId }}"
                        title="Filters">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        @if($ticketActiveFilterCount > 0)
                            <span class="badge text-bg-secondary ms-1">{{ $ticketActiveFilterCount }}</span>
                        @endif
                    </button>
                </div>

                <div id="{{ $ticketFiltersCollapseId }}" class="collapse {{ $ticketFiltersOpen ? 'show' : '' }} mt-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label for="ticket_index_sort" class="form-label small text-muted mb-1">Sort</label>
                            <select id="ticket_index_sort" name="sort" class="form-select form-select-sm">
                                <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Newest updated</option>
                                <option value="oldest" @selected(($filters['sort'] ?? 'newest') === 'oldest')>Oldest updated</option>
                                <option value="priority" @selected(($filters['sort'] ?? 'newest') === 'priority')>Priority</option>
                                <option value="sla" @selected(($filters['sort'] ?? 'newest') === 'sla')>SLA risk</option>
                                <option value="unread" @selected(($filters['sort'] ?? 'newest') === 'unread')>Unread first</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label for="ticket_index_status_id" class="form-label small text-muted mb-1">Status</label>
                            <select id="ticket_index_status_id" name="status_id" class="form-select form-select-sm">
                                <option value="">All statuses</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status->id }}" @selected(($filters['status_id'] ?? '') == $status->id)>{{ $status->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label for="ticket_index_priority_id" class="form-label small text-muted mb-1">Priority</label>
                            <select id="ticket_index_priority_id" name="priority_id" class="form-select form-select-sm">
                                <option value="">All priorities</option>
                                @foreach ($priorities as $priority)
                                    <option value="{{ $priority->id }}" @selected(($filters['priority_id'] ?? '') == $priority->id)>P{{ $priority->level }} {{ $priority->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label for="ticket_index_category_id" class="form-label small text-muted mb-1">Category</label>
                            <select id="ticket_index_category_id" name="category_id" class="form-select form-select-sm">
                                <option value="">All categories</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label for="ticket_index_queue_id" class="form-label small text-muted mb-1">Queue</label>
                            <select id="ticket_index_queue_id" name="queue_id" class="form-select form-select-sm">
                                <option value="">All queues</option>
                                @foreach ($queues as $queue)
                                    <option value="{{ $queue->id }}" @selected(($filters['queue_id'] ?? '') == $queue->id)>{{ $queue->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label for="ticket_index_lifecycle" class="form-label small text-muted mb-1">Lifecycle</label>
                            <select id="ticket_index_lifecycle" name="lifecycle" class="form-select form-select-sm">
                                <option value="open" @selected(($filters['lifecycle'] ?? 'all') === 'open')>Open only</option>
                                <option value="all" @selected(($filters['lifecycle'] ?? 'all') === 'all')>Open and closed</option>
                                <option value="closed" @selected(($filters['lifecycle'] ?? 'all') === 'closed')>Closed only</option>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label for="ticket_index_ownership" class="form-label small text-muted mb-1">Ownership</label>
                            <select id="ticket_index_ownership" name="ownership" class="form-select form-select-sm">
                                <option value="mine_unassigned" @selected(($filters['ownership'] ?? 'mine_unassigned') === 'mine_unassigned')>Mine + Unassigned</option>
                                <option value="mine" @selected(($filters['ownership'] ?? 'mine_unassigned') === 'mine')>Mine</option>
                                <option value="all" @selected(($filters['ownership'] ?? 'mine_unassigned') === 'all')>All</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <div class="d-flex flex-wrap gap-3 pt-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="ticket_index_unread" name="unread" value="1" @checked(! empty($filters['unread']))>
                                    <label class="form-check-label small" for="ticket_index_unread">Unread only</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="ticket_index_unassigned" name="unassigned" value="1" @checked(! empty($filters['unassigned']))>
                                    <label class="form-check-label small" for="ticket_index_unassigned">Unassigned only</label>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-2 ms-md-auto d-grid">
                            <button type="submit" class="btn btn-sm btn-secondary">Apply filters</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-muted">
                <span id="ticketBulkCount">0</span> selected
            </div>
            <button
                id="ticketBulkMergeButton"
                type="button"
                class="btn btn-sm btn-outline-warning"
                data-bs-toggle="modal"
                data-bs-target="#ticketBulkMergeModal"
                disabled>
                <i class="bi bi-intersect" aria-hidden="true"></i>
                Merge selected
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 2.75rem;">
                            <input
                                id="ticket_bulk_select_all"
                                class="form-check-input"
                                type="checkbox"
                                aria-label="Select all tickets on this page">
                        </th>
                        <th>
                            <a href="{{ $sortLink('ticket') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Ticket <i class="bi {{ $sortIcon('ticket') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('subject') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Subject <i class="bi {{ $sortIcon('subject') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('client') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Client <i class="bi {{ $sortIcon('client') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('technician') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Technician <i class="bi {{ $sortIcon('technician') }}"></i>
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
                        <th>
                            <a href="{{ $sortLink('sla', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                SLA <i class="bi {{ $sortIcon('sla') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('status') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Status <i class="bi {{ $sortIcon('status') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('updated', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Updated <i class="bi {{ $sortIcon('updated') }}"></i>
                            </a>
                        </th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tickets as $ticket)
                        <tr
                            class="cursor-pointer ticket-index-row"
                            role="link"
                            tabindex="0"
                            data-href="{{ route('tech.tickets.show', $ticket) }}"
                            data-ticket-key="{{ $ticket->ticket_key }}"
                            aria-label="Open ticket {{ $ticket->ticket_key }}"
                        >
                            <td class="text-center">
                                <input
                                    class="form-check-input ticket-bulk-checkbox"
                                    type="checkbox"
                                    value="{{ $ticket->id }}"
                                    data-ticket-key="{{ $ticket->ticket_key }}"
                                    data-ticket-subject="{{ $ticket->subject }}"
                                    aria-label="Select ticket {{ $ticket->ticket_key }}">
                            </td>
                            <td>
                                <a href="{{ route('tech.tickets.show', $ticket) }}">{{ $ticket->ticket_key }}</a>
                                @if ($ticket->is_unread)
                                    <span class="badge text-bg-primary ms-1">Unread</span>
                                @endif
                            </td>
                            <td>{{ $ticket->subject }}</td>
                            <td>{{ $ticket->client?->name ?? 'Unassigned' }}</td>
                            <td>
                                @if($ticket->owner)
                                    {{ $ticket->owner->name }}
                                @else
                                    <span class="text-muted">Unassigned</span>
                                @endif
                            </td>
                            <td>{{ $ticket->queue?->name }}</td>
                            <td>P{{ $ticket->priority?->level }} {{ $ticket->priority?->name }}</td>
                            <td>
                                @php
                                    $responseOverdue = $ticket->first_response_due_at && ! $ticket->first_responded_at && $ticket->first_response_due_at->isPast();
                                    $resolveOverdue = $ticket->resolve_due_at && ! $ticket->resolved_at && $ticket->resolve_due_at->isPast();
                                    $nextSlaTarget = ! $ticket->first_responded_at ? $ticket->first_response_due_at : $ticket->resolve_due_at;
                                    $slaTone = $responseOverdue || $resolveOverdue ? 'text-bg-danger' : ($nextSlaTarget ? 'text-bg-light border' : 'text-bg-secondary');
                                    $slaLabel = $responseOverdue ? 'Response overdue' : ($resolveOverdue ? 'Resolve overdue' : ($nextSlaTarget ? $nextSlaTarget->diffForHumans(null, true) : 'No SLA'));
                                @endphp
                                <div class="d-flex flex-column gap-1 align-items-start">
                                    <span class="badge {{ $slaTone }}">{{ $slaLabel }}</span>
                                    @if($ticket->sla)
                                        <span class="text-muted small">{{ $ticket->sla->name }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>{{ $ticket->status?->name }}</td>
                            <td>{{ $ticket->updated_at?->diffForHumans() }}</td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <form method="POST" action="{{ route('tech.tickets.not-ticket', $ticket) }}" onsubmit="return confirm('Return {{ $ticket->ticket_key }} to Inbox and prevent matching emails from becoming tickets automatically?');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Mark as not ticket">
                                            <i class="bi bi-inbox" aria-hidden="true"></i>
                                            <span class="visually-hidden">Mark {{ $ticket->ticket_key }} as not ticket</span>
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('tech.tickets.destroy', $ticket) }}" onsubmit="return confirm('Delete {{ $ticket->ticket_key }}? This hides the ticket from normal views.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete ticket">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                            <span class="visually-hidden">Delete {{ $ticket->ticket_key }}</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">No tickets found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($tickets->hasPages())
            <div class="card-footer">
                {{ $tickets->links() }}
            </div>
        @endif
    </div>

    <!-- Bulk merge modal: the primary ticket is chosen from the tickets already selected in the list. -->
    <div class="modal fade" id="ticketBulkMergeModal" tabindex="-1" aria-labelledby="ticketBulkMergeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('tech.tickets.merge') }}" class="modal-content" onsubmit="return confirm('Merge the selected tickets into the chosen primary ticket?');">
                @csrf
                <div id="ticketBulkHiddenInputs"></div>
                <div class="modal-header">
                    <h2 class="modal-title h5" id="ticketBulkMergeModalLabel">Merge Selected Tickets</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Primary ticket</label>
                        <div id="ticketBulkPrimaryOptions" class="list-group small"></div>
                        @error('target_ticket_id')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                        @error('ticket_ids')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                        <div class="form-text">All other selected tickets are merged into this ticket and hidden from normal ticket lists.</div>
                    </div>
                    <div class="mb-0">
                        <label for="bulk_merge_reason" class="form-label">Reason</label>
                        <textarea id="bulk_merge_reason" name="reason" class="form-control" rows="3">{{ old('reason') }}</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Merge tickets</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const stopwatchPrefix = 'ticket-stopwatch-';
        const bulkCheckboxes = Array.from(document.querySelectorAll('.ticket-bulk-checkbox'));
        const bulkSelectAll = document.getElementById('ticket_bulk_select_all');
        const bulkCount = document.getElementById('ticketBulkCount');
        const bulkMergeButton = document.getElementById('ticketBulkMergeButton');
        const bulkHiddenInputs = document.getElementById('ticketBulkHiddenInputs');
        const bulkPrimaryOptions = document.getElementById('ticketBulkPrimaryOptions');

        const hasActiveTimer = function (ticketKey) {
            try {
                const state = JSON.parse(localStorage.getItem(stopwatchPrefix + ticketKey) || 'null');

                return !! state && (state.running || Number(state.elapsedMs || 0) > 0);
            } catch (error) {
                return false;
            }
        };

        const selectedTickets = function () {
            return bulkCheckboxes
                .filter(function (checkbox) {
                    return checkbox.checked;
                })
                .map(function (checkbox) {
                    return {
                        id: checkbox.value,
                        key: checkbox.dataset.ticketKey,
                        subject: checkbox.dataset.ticketSubject,
                    };
                });
        };

        const syncBulkState = function () {
            const selected = selectedTickets();
            bulkCount.textContent = selected.length;
            bulkMergeButton.disabled = selected.length < 2;

            if (bulkSelectAll) {
                bulkSelectAll.checked = selected.length > 0 && selected.length === bulkCheckboxes.length;
                bulkSelectAll.indeterminate = selected.length > 0 && selected.length < bulkCheckboxes.length;
            }
        };

        const buildBulkMergeModal = function () {
            const selected = selectedTickets();
            bulkHiddenInputs.innerHTML = '';
            bulkPrimaryOptions.innerHTML = '';

            selected.forEach(function (ticket, index) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'ticket_ids[]';
                hiddenInput.value = ticket.id;
                bulkHiddenInputs.appendChild(hiddenInput);

                const option = document.createElement('label');
                option.className = 'list-group-item d-flex gap-2 align-items-start';

                const radio = document.createElement('input');
                radio.className = 'form-check-input mt-1';
                radio.type = 'radio';
                radio.name = 'target_ticket_id';
                radio.value = ticket.id;
                radio.required = true;
                radio.checked = index === 0;

                const text = document.createElement('span');
                text.className = 'min-w-0';
                text.innerHTML = '<span class="fw-semibold"></span><span class="d-block text-muted text-truncate"></span>';
                text.querySelector('.fw-semibold').textContent = ticket.key;
                text.querySelector('.text-muted').textContent = ticket.subject || 'No subject';

                option.appendChild(radio);
                option.appendChild(text);
                bulkPrimaryOptions.appendChild(option);
            });
        };

        const syncTimerRows = function () {
            document.querySelectorAll('tr[data-ticket-key]').forEach(function (row) {
                row.classList.toggle('ticket-row-active-timer', hasActiveTimer(row.dataset.ticketKey));
            });
        };

        document.querySelectorAll('tr[data-href]').forEach(function (row) {
            row.addEventListener('click', function (event) {
                // Keep normal link behavior for real links inside the row.
                if (event.target.closest('a, button, input, select, textarea')) {
                    return;
                }

                window.location.href = row.dataset.href;
            });

            row.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    window.location.href = row.dataset.href;
                }
            });
        });

        bulkCheckboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', syncBulkState);
        });

        if (bulkSelectAll) {
            bulkSelectAll.addEventListener('change', function () {
                bulkCheckboxes.forEach(function (checkbox) {
                    checkbox.checked = bulkSelectAll.checked;
                });

                syncBulkState();
            });
        }

        if (bulkMergeButton) {
            bulkMergeButton.addEventListener('click', buildBulkMergeModal);
        }

        syncBulkState();
        syncTimerRows();
        window.setInterval(syncTimerRows, 15000);
        window.addEventListener('storage', syncTimerRows);
    });
</script>
@endsection

@section('sidebar')
    <div class="pt-3">
        <x-nav.work-menu />

        <hr class="my-3">

        @php
            // Accordion sections stay compact by default, but open when the user has active state in that section.
            $clientsOpen = ! empty($filters['client_id']);
        @endphp

        <div class="accordion accordion-flush" id="ticketSidebarAccordion">
            <div class="accordion-item border rounded overflow-hidden">
                <h2 class="accordion-header" id="ticketClientsHeading">
                    <button
                        class="accordion-button py-2 px-3 {{ $clientsOpen ? '' : 'collapsed' }}"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#ticketClientsCollapse"
                        aria-expanded="{{ $clientsOpen ? 'true' : 'false' }}"
                        aria-controls="ticketClientsCollapse">
                        <span class="d-flex align-items-center gap-2">
                            <i class="bi bi-building" aria-hidden="true"></i>
                            <span>Clients</span>
                            @if(! empty($filters['client_id']))
                                <span class="badge text-bg-secondary">Scoped</span>
                            @endif
                        </span>
                    </button>
                </h2>
                <div
                    id="ticketClientsCollapse"
                    class="accordion-collapse collapse {{ $clientsOpen ? 'show' : '' }}"
                    aria-labelledby="ticketClientsHeading"
                    data-bs-parent="#ticketSidebarAccordion">
                    <div class="accordion-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="h6 mb-0">Clients</h3>
                            @if (! empty($filters['client_id']))
                                {{-- Clear only the client filter while keeping the rest of the current filter state. --}}
                                <a class="small" href="{{ route('tech.tickets.index', array_filter([
                                    'q' => $filters['q'] ?? null,
                                    'sort' => $filters['sort'] ?? null,
                                    'status_id' => $filters['status_id'] ?? null,
                                    'priority_id' => $filters['priority_id'] ?? null,
                                    'category_id' => $filters['category_id'] ?? null,
                                    'queue_id' => $filters['queue_id'] ?? null,
                                    'lifecycle' => $filters['lifecycle'] ?? null,
                                    'unread' => $filters['unread'] ?? null,
                                    'unassigned' => $filters['unassigned'] ?? null,
                                    'ownership' => $filters['ownership'] ?? null,
                                ])) }}">Clear</a>
                            @endif
                        </div>

                        {{-- Long client lists scroll independently so the filters and page footer remain reachable. --}}
                        <div class="list-group list-group-flush small overflow-auto pe-1" style="max-height: clamp(10rem, calc(100vh - 34rem), 26rem);">
                            @foreach ($clients as $client)
                                {{-- Client links rebuild the query string to preserve the active ticket filters. --}}
                                <a
                                    href="{{ route('tech.tickets.index', array_filter([
                                        'q' => $filters['q'] ?? null,
                                        'sort' => $filters['sort'] ?? null,
                                        'status_id' => $filters['status_id'] ?? null,
                                        'priority_id' => $filters['priority_id'] ?? null,
                                        'category_id' => $filters['category_id'] ?? null,
                                        'queue_id' => $filters['queue_id'] ?? null,
                                        'lifecycle' => $filters['lifecycle'] ?? null,
                                        'unread' => $filters['unread'] ?? null,
                                        'unassigned' => $filters['unassigned'] ?? null,
                                        'ownership' => $filters['ownership'] ?? null,
                                        'client_id' => $client->id,
                                    ])) }}"
                                    class="list-group-item list-group-item-action px-0 py-2 border-0 @if (($filters['client_id'] ?? '') == $client->id) active px-2 rounded @endif"
                                >
                                    <div class="text-truncate">{{ $client->name }}</div>
                                    @if ($client->client_number)
                                        <div class="small @if (($filters['client_id'] ?? '') == $client->id) text-white-50 @else text-muted @endif">{{ $client->client_number }}</div>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')
    @if(($mergeSuggestionSettings['ai_merge_enabled'] ?? false) && $mergeSuggestions->isNotEmpty())
        {{-- Merge suggestions: local similarity produces candidates; technicians still approve the actual merge. --}}
        <x-card.default title="Merge suggestions">
            @foreach($mergeSuggestions as $suggestion)
                <div class="border-bottom pb-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div class="small">
                            @foreach($suggestion['sources'] as $sourceTicket)
                                <a href="{{ route('tech.tickets.show', $sourceTicket) }}" class="fw-semibold">{{ $sourceTicket->ticket_key }}</a>@if(! $loop->last), @endif
                            @endforeach
                            <span class="text-muted">into</span>
                            <a href="{{ route('tech.tickets.show', $suggestion['target']) }}" class="fw-semibold">{{ $suggestion['target']->ticket_key }}</a>
                        </div>
                        <span class="badge text-bg-warning">{{ $suggestion['confidence'] }}%</span>
                    </div>
                    <div class="small text-muted mb-2">
                        @foreach($suggestion['sources'] as $sourceTicket)
                            <div class="text-truncate">{{ $sourceTicket->subject }}</div>
                        @endforeach
                        <div class="text-truncate">{{ $suggestion['target']->subject }}</div>
                        @if($suggestion['target']->client)
                            <div>{{ $suggestion['target']->client->name }}</div>
                        @endif
                        <div class="mt-2">
                            <span class="fw-semibold text-body">Why:</span>
                            {{ $suggestion['details'] }}
                            <span class="d-block">{{ $suggestion['reason'] }}</span>
                        </div>
                    </div>
                    <div class="d-grid gap-2">
                        <form method="POST" action="{{ route('tech.tickets.merge') }}" onsubmit="return confirm('Merge {{ $suggestion['sources']->count() }} ticket(s) into {{ $suggestion['target']->ticket_key }}?');">
                            @csrf
                            <input type="hidden" name="ticket_ids[]" value="{{ $suggestion['target']->id }}">
                            @foreach($suggestion['sources'] as $sourceTicket)
                                <input type="hidden" name="ticket_ids[]" value="{{ $sourceTicket->id }}">
                            @endforeach
                            <input type="hidden" name="target_ticket_id" value="{{ $suggestion['target']->id }}">
                            <input type="hidden" name="reason" value="Merge suggestion: {{ $suggestion['reason'] }}">
                            <button type="submit" class="btn btn-sm btn-outline-warning w-100">
                                <i class="bi bi-intersect" aria-hidden="true"></i>
                                Merge suggestion
                            </button>
                        </form>
                        <form method="POST" action="{{ route('tech.tickets.merge-suggestions.dismiss') }}">
                            @csrf
                            <input type="hidden" name="ticket_ids[]" value="{{ $suggestion['target']->id }}">
                            @foreach($suggestion['sources'] as $sourceTicket)
                                <input type="hidden" name="ticket_ids[]" value="{{ $sourceTicket->id }}">
                            @endforeach
                            <input type="hidden" name="reason" value="Dismissed from ticket list.">
                            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                                Dismiss suggestion
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </x-card.default>
    @endif

    {{-- Right rail: lightweight operational counters, intentionally separate from the main list. --}}
    <x-card.default title="Ticket stats">
        <div class="row row-cols-2 g-2 text-center">
            <div class="col">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Open</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['open'] }}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Mine</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['mine'] }}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Unread</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['unread'] }}</div>
                </div>
            </div>
            <div class="col">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Unassigned</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['unassigned'] }}</div>
                </div>
            </div>
        </div>
    </x-card.default>
@endsection
