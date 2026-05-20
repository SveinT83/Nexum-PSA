@extends('layouts.default_tech')

@section('title', 'Storage Picking List')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">Picking List</h1>
            <p class="text-muted mb-0">Reserved ticket items, sorted by what can be picked now.</p>
        </div>
        <a href="{{ route('tech.storage.index') }}" class="btn btn-outline-primary">
            <i class="bi bi-box-seam"></i> Inventory
        </a>
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

        {{-- Picking filters keep the warehouse queue focused on available work. --}}
        <form method="GET" action="{{ route('tech.storage.picking') }}" class="card mb-4">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label for="q" class="form-label">Search</label>
                        <input type="search" id="q" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"
                               placeholder="Ticket, subject, SKU, or item name">
                    </div>
                    <div class="col-md-4">
                        <label for="status" class="form-label">Pick status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="" @selected(($filters['status'] ?? '') === '')>All reserved items</option>
                            <option value="ready" @selected(($filters['status'] ?? '') === 'ready')>Ready to pick</option>
                            <option value="waiting" @selected(($filters['status'] ?? '') === 'waiting')>Waiting for stock</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-grid">
                        <button type="submit" class="btn btn-outline-primary">Apply</button>
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
                                <form method="POST" action="{{ route('tech.storage.picking.pick', $entry) }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-success"
                                        @disabled(! $canPick)
                                        title="{{ $canPick ? 'Pick item from stock and send it to Economy.' : 'Not enough on-hand stock to pick this item.' }}">
                                        Pick
                                    </button>
                                </form>
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
