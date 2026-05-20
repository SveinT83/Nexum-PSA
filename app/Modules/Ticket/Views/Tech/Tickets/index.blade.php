@extends('layouts.default_tech')

@section('title', 'Tickets')

@section('pageName')
    <h3>Tickets</h3>
@endsection

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Tickets</h1>
        <x-buttons.addlink url="{{ route('tech.tickets.create') }}">New ticket</x-buttons.addlink>
    </div>
@endsection

@section('content')
<div class="container-fluid px-0">
    <style>
        .ticket-row-active-timer > * {
            --bs-table-bg: #eaf4ff;
            --bs-table-striped-bg: #eaf4ff;
            --bs-table-hover-bg: #dceeff;
        }
    </style>

    {{-- Main pane: keep the ticket index focused on the list; filters and stats live in the side rails. --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Subject</th>
                        <th>Client</th>
                        <th>Technician</th>
                        <th>Queue</th>
                        <th>Priority</th>
                        <th>SLA</th>
                        <th>Status</th>
                        <th>Updated</th>
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
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No tickets found.</td>
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
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const stopwatchPrefix = 'ticket-stopwatch-';

        const hasActiveTimer = function (ticketKey) {
            try {
                const state = JSON.parse(localStorage.getItem(stopwatchPrefix + ticketKey) || 'null');

                return !! state && (state.running || Number(state.elapsedMs || 0) > 0);
            } catch (error) {
                return false;
            }
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
            $activeFilterCount = collect([
                filled($filters['q'] ?? null),
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

            $filtersOpen = $activeFilterCount > 0;
            $clientsOpen = ! empty($filters['client_id']);
        @endphp

        <div class="accordion accordion-flush" id="ticketSidebarAccordion">
            <div class="accordion-item border rounded mb-2 overflow-hidden">
                <h2 class="accordion-header" id="ticketFiltersHeading">
                    <button
                        class="accordion-button py-2 px-3 {{ $filtersOpen ? '' : 'collapsed' }}"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#ticketFiltersCollapse"
                        aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}"
                        aria-controls="ticketFiltersCollapse">
                        <span class="d-flex align-items-center gap-2">
                            <i class="bi bi-funnel" aria-hidden="true"></i>
                            <span>Filters</span>
                            @if($activeFilterCount > 0)
                                <span class="badge text-bg-secondary">{{ $activeFilterCount }}</span>
                            @endif
                        </span>
                    </button>
                </h2>
                <div
                    id="ticketFiltersCollapse"
                    class="accordion-collapse collapse {{ $filtersOpen ? 'show' : '' }}"
                    aria-labelledby="ticketFiltersHeading"
                    data-bs-parent="#ticketSidebarAccordion">
                    <div class="accordion-body p-3">
                        {{-- Filter form: every control submits as query string values consumed by TicketIndexQuery. --}}
                        <form method="GET" action="{{ route('tech.tickets.index') }}">
                            {{-- Preserve the selected client when applying search, sort, status, queue, or ownership filters. --}}
                            <input type="hidden" name="client_id" value="{{ $filters['client_id'] ?? '' }}">

                            <div class="mb-2">
                                <label for="q" class="form-label small text-muted mb-1">Search</label>
                                <input id="q" name="q" type="search" class="form-control form-control-sm" value="{{ $filters['q'] ?? '' }}" placeholder="Ticket key or subject">
                            </div>

                            <div class="mb-2">
                                <label for="sort" class="form-label small text-muted mb-1">Sort</label>
                                <select id="sort" name="sort" class="form-select form-select-sm">
                                    <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Newest updated</option>
                                    <option value="oldest" @selected(($filters['sort'] ?? 'newest') === 'oldest')>Oldest updated</option>
                                    <option value="priority" @selected(($filters['sort'] ?? 'newest') === 'priority')>Priority</option>
                                    <option value="sla" @selected(($filters['sort'] ?? 'newest') === 'sla')>SLA risk</option>
                                    <option value="unread" @selected(($filters['sort'] ?? 'newest') === 'unread')>Unread first</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label for="status_id" class="form-label small text-muted mb-1">Status</label>
                                <select id="status_id" name="status_id" class="form-select form-select-sm">
                                    <option value="">All statuses</option>
                                    @foreach ($statuses as $status)
                                        <option value="{{ $status->id }}" @selected(($filters['status_id'] ?? '') == $status->id)>{{ $status->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-2">
                                <label for="priority_id" class="form-label small text-muted mb-1">Priority</label>
                                <select id="priority_id" name="priority_id" class="form-select form-select-sm">
                                    <option value="">All priorities</option>
                                    @foreach ($priorities as $priority)
                                        <option value="{{ $priority->id }}" @selected(($filters['priority_id'] ?? '') == $priority->id)>P{{ $priority->level }} {{ $priority->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-2">
                                <label for="category_id" class="form-label small text-muted mb-1">Category</label>
                                <select id="category_id" name="category_id" class="form-select form-select-sm">
                                    <option value="">All categories</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-2">
                                <label for="queue_id" class="form-label small text-muted mb-1">Queue</label>
                                <select id="queue_id" name="queue_id" class="form-select form-select-sm">
                                    <option value="">All queues</option>
                                    @foreach ($queues as $queue)
                                        <option value="{{ $queue->id }}" @selected(($filters['queue_id'] ?? '') == $queue->id)>{{ $queue->name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-2">
                                <label for="lifecycle" class="form-label small text-muted mb-1">Lifecycle</label>
                                <select id="lifecycle" name="lifecycle" class="form-select form-select-sm">
                                    <option value="open" @selected(($filters['lifecycle'] ?? 'all') === 'open')>Open only</option>
                                    <option value="all" @selected(($filters['lifecycle'] ?? 'all') === 'all')>Open and closed</option>
                                    <option value="closed" @selected(($filters['lifecycle'] ?? 'all') === 'closed')>Closed only</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="ownership" class="form-label small text-muted mb-1">Ownership</label>
                                <select id="ownership" name="ownership" class="form-select form-select-sm">
                                    <option value="mine_unassigned" @selected(($filters['ownership'] ?? 'mine_unassigned') === 'mine_unassigned')>Mine + Unassigned</option>
                                    <option value="mine" @selected(($filters['ownership'] ?? 'mine_unassigned') === 'mine')>Mine</option>
                                    <option value="all" @selected(($filters['ownership'] ?? 'mine_unassigned') === 'all')>All</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="unread" name="unread" value="1" @checked(! empty($filters['unread']))>
                                    <label class="form-check-label small" for="unread">Unread only</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="unassigned" name="unassigned" value="1" @checked(! empty($filters['unassigned']))>
                                    <label class="form-check-label small" for="unassigned">Unassigned only</label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-sm btn-secondary w-100">Apply</button>
                        </form>
                    </div>
                </div>
            </div>

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
    {{-- Right rail: lightweight operational counters, intentionally separate from the main list. --}}
    <x-card.default title="Ticket stats">
        <div class="row g-2 text-center">
            <div class="col-4">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Open</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['open'] }}</div>
                </div>
            </div>
            <div class="col-4">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Mine</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['mine'] }}</div>
                </div>
            </div>
            <div class="col-4">
                <div class="border rounded bg-light py-2 px-1">
                    <div class="small text-muted text-uppercase">Unread</div>
                    <div class="fw-bold fs-5 lh-1">{{ $stats['unread'] }}</div>
                </div>
            </div>
        </div>
    </x-card.default>
@endsection
