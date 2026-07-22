@extends('layouts.default_tech')

@section('title', 'Storage Picking List')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="mb-0">Picking List</h1>
        <x-buttons.back :url="route('tech.storage.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @if($errors->has('pick'))
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                {{ $errors->first('pick') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        @php
            $pickingActiveFilterCount = collect([
                filled($filters['status'] ?? null),
            ])->filter()->count();
            $pickingFiltersOpen = $pickingActiveFilterCount > 0;
        @endphp

        {{-- Picking filters keep the warehouse queue focused on available work. --}}
        <form method="GET" action="{{ route('tech.storage.picking') }}" class="card mb-4">
            <div class="card-body">
                <label for="picking_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
                <div class="input-group input-group-sm">
                    <input type="search" id="picking_search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"
                           placeholder="Ticket, subject, SKU, or item name">
                    <button type="submit" class="btn btn-outline-secondary">Search</button>
                    <button
                        class="btn btn-outline-secondary"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#pickingFiltersCollapse"
                        aria-expanded="{{ $pickingFiltersOpen ? 'true' : 'false' }}"
                        aria-controls="pickingFiltersCollapse"
                        title="Filters">
                        <i class="bi bi-funnel" aria-hidden="true"></i>
                        @if($pickingActiveFilterCount > 0)
                            <span class="badge text-bg-secondary ms-1">{{ $pickingActiveFilterCount }}</span>
                        @endif
                    </button>
                </div>

                <div id="pickingFiltersCollapse" class="collapse {{ $pickingFiltersOpen ? 'show' : '' }} mt-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label for="status" class="form-label small text-muted mb-1">Pick status</label>
                            <select id="status" name="status" class="form-select form-select-sm">
                                <option value="" @selected(($filters['status'] ?? '') === '')>All reserved items</option>
                                <option value="ready" @selected(($filters['status'] ?? '') === 'ready')>Ready to pick</option>
                                <option value="waiting" @selected(($filters['status'] ?? '') === 'waiting')>Waiting for stock</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-sm btn-secondary">Apply filters</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        {{-- Ticket reservations are grouped into one operational pick queue. --}}
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Item</th>
                        <th>Ticket</th>
                        <th>Client</th>
                        <th>Location</th>
                        <th class="text-end">Reserved</th>
                        <th class="text-end">On-hand</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($reservations as $entry)
                        @php
                            $item = $entry->storageItem;
                            $ticket = $entry->ticket;
                            $canPick = ($item?->qty_on_hand ?? 0) >= $entry->quantity;
                            $location = collect([
                                $item?->warehouse?->name,
                                $item?->box ? ($item->box->code_human ?: 'Box #' . $item->box->id) : null,
                            ])->filter()->implode(' / ');
                        @endphp
                        <tr class="{{ $canPick ? '' : 'table-light' }}">
                            <td>
                                @if($item)
                                    <a href="{{ route('tech.storage.items.show', $item) }}" class="fw-semibold text-decoration-none">
                                        {{ $entry->item_sku ?: $item->sku }}
                                    </a>
                                @else
                                    <span class="fw-semibold">{{ $entry->item_sku ?: 'Unknown SKU' }}</span>
                                @endif
                                <div class="text-muted small">{{ $entry->item_name }}</div>
                            </td>
                            <td>
                                @if($ticket)
                                    <a href="{{ route('tech.tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_key }}</a>
                                    <div class="text-muted small text-truncate" style="max-width: 16rem;">{{ $ticket->subject }}</div>
                                @else
                                    <span class="text-muted">Missing ticket</span>
                                @endif
                            </td>
                            <td>
                                <span>{{ $ticket?->client?->name ?? 'No client' }}</span>
                                <div class="text-muted small">{{ $ticket?->owner?->name ?? 'Unassigned' }}</div>
                            </td>
                            <td>{{ $location ?: 'No location' }}</td>
                            <td class="text-end">{{ $entry->quantity }}</td>
                            <td class="text-end">{{ $item?->qty_on_hand ?? 0 }}</td>
                            <td>
                                @if($canPick)
                                    <span class="badge text-bg-success">Ready</span>
                                @else
                                    <span class="badge text-bg-warning">Waiting for stock</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex justify-content-end gap-2">
                                    @if($ticket)
                                        <a
                                            href="{{ route('tech.tickets.show', $ticket) }}"
                                            class="btn btn-sm btn-outline-primary text-nowrap"
                                            aria-label="Open ticket {{ $ticket->ticket_key }}"
                                            title="Open the ticket to edit this reservation before picking.">
                                            <i class="bi bi-ticket-perforated me-1" aria-hidden="true"></i>Open ticket
                                        </a>
                                    @endif
                                    <form method="POST" action="{{ route('tech.storage.picking.pick', $entry) }}" class="mb-0">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-success"
                                            @disabled(! $canPick)
                                            title="{{ $canPick ? 'Pick item from stock and send it to Economy.' : 'Not enough on-hand stock to pick this item.' }}">
                                            Pick
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                No reserved ticket items match this view.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($reservations->hasPages())
                <div class="card-footer">
                    {{ $reservations->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection

@section('rightbar')
    @include('storage::Tech.Storage.partials.picking-documentation-card')

    {{-- Picking summary shows the warehouse workload without opening Economy or Tickets. --}}
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Pick Queue</h5>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-8">Ready</dt>
                <dd class="col-4 text-end">{{ $stats['ready'] }}</dd>
                <dt class="col-8">Waiting</dt>
                <dd class="col-4 text-end">{{ $stats['waiting'] }}</dd>
                <dt class="col-8">Reserved qty</dt>
                <dd class="col-4 text-end">{{ $stats['reserved_quantity'] }}</dd>
                <dt class="col-8">Tickets</dt>
                <dd class="col-4 text-end">{{ $stats['tickets'] }}</dd>
            </dl>
        </div>
    </div>
@endsection
