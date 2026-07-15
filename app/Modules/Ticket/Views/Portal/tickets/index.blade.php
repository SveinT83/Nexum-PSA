@extends('customerportal::layouts.portal')

@section('title', 'Tickets')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Ticket List -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Tickets</h1>
            <div class="small text-muted">{{ $context->client->name }}{{ $context->site ? ' - '.$context->site->name : '' }}</div>
        </div>
        <a href="{{ route('customer-portal.tickets.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
            New ticket
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Ticket</th>
                        <th>Status</th>
                        <th>Site</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tickets as $ticket)
                        <tr>
                            <td>
                                <a href="{{ route('customer-portal.tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">{{ $ticket->ticket_key }}</a>
                                <div class="small text-muted">{{ $ticket->subject }}</div>
                            </td>
                            <td><span class="badge text-bg-light border">{{ $access->publicStatusLabel($ticket) }}</span></td>
                            <td>{{ $ticket->site?->name ?: 'All sites' }}</td>
                            <td class="text-muted small">{{ $ticket->updated_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No visible tickets for this portal scope.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $tickets->links() }}
    </div>
@endsection
