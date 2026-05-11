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
    {{-- Main pane: keep the ticket index focused on the list; filters and stats live in the side rails. --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Subject</th>
                        <th>Client</th>
                        <th>Queue</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td>
                                <a href="{{ route('tech.tickets.show', $ticket) }}">{{ $ticket->ticket_key }}</a>
                                @if ($ticket->is_unread)
                                    <span class="badge text-bg-primary ms-1">Unread</span>
                                @endif
                            </td>
                            <td>{{ $ticket->subject }}</td>
                            <td>{{ $ticket->client?->name ?? 'Unassigned' }}</td>
                            <td>{{ $ticket->queue?->name }}</td>
                            <td>P{{ $ticket->priority?->level }} {{ $ticket->priority?->name }}</td>
                            <td>{{ $ticket->status?->name }}</td>
                            <td>{{ $ticket->updated_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No tickets found.</td>
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
@endsection

@section('sidebar')
    <div class="pt-3">
        {{-- Filter form: every control submits as query string values consumed by TicketIndexQuery. --}}
        <form method="GET" action="{{ route('tech.tickets.index') }}" class="mb-3">
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
                <label for="queue_id" class="form-label small text-muted mb-1">Queue</label>
                <select id="queue_id" name="queue_id" class="form-select form-select-sm">
                    <option value="">All queues</option>
                    @foreach ($queues as $queue)
                        <option value="{{ $queue->id }}" @selected(($filters['queue_id'] ?? '') == $queue->id)>{{ $queue->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-3">
                <label for="ownership" class="form-label small text-muted mb-1">Ownership</label>
                <select id="ownership" name="ownership" class="form-select form-select-sm">
                    <option value="mine" @selected(($filters['ownership'] ?? 'mine') === 'mine')>Mine</option>
                    <option value="all" @selected(($filters['ownership'] ?? 'mine') === 'all')>All</option>
                </select>
            </div>

            <button type="submit" class="btn btn-sm btn-secondary w-100">Apply</button>
        </form>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0">Clients</h2>
            @if (! empty($filters['client_id']))
                {{-- Clear only the client filter while keeping the rest of the current filter state. --}}
                <a class="small" href="{{ route('tech.tickets.index', array_filter([
                    'q' => $filters['q'] ?? null,
                    'sort' => $filters['sort'] ?? null,
                    'status_id' => $filters['status_id'] ?? null,
                    'queue_id' => $filters['queue_id'] ?? null,
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
                        'queue_id' => $filters['queue_id'] ?? null,
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
@endsection

@section('rightbar')
    {{-- Right rail: lightweight operational counters, intentionally separate from the main list. --}}
    <x-card.default title="Ticket stats">
        <div class="list-group list-group-flush">
            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                <span class="text-muted">Open</span>
                <strong>{{ $stats['open'] }}</strong>
            </div>
            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                <span class="text-muted">Mine</span>
                <strong>{{ $stats['mine'] }}</strong>
            </div>
            <div class="list-group-item px-0 d-flex justify-content-between align-items-center">
                <span class="text-muted">Unread</span>
                <strong>{{ $stats['unread'] }}</strong>
            </div>
        </div>
    </x-card.default>
@endsection
