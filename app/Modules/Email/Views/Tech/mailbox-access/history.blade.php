@extends('layouts.default_tech')

@section('title', 'Mailbox Access History')

@section('pageHeader')
    <h1>Mailbox access history</h1>
@endsection

@section('sidebar')
    @include('email::Tech.mailbox-access.partials.sidebar')
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Metadata-Only History Filter -->
    <!-- ------------------------------------------------- -->
    <form method="get" action="{{ route('tech.mail.access.history') }}" class="card card-body py-2 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <label for="mailbox-history-account" class="form-label small fw-semibold mb-1">Mailbox</label>
                <select id="mailbox-history-account" name="account" class="form-select form-select-sm">
                    <option value="">{{ $canAuditAll ? 'All mailboxes' : 'All my personal mailboxes' }}</option>
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected((int) $accountId === (int) $account->id)>{{ $account->address }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- Metadata-Only Access Events -->
    <!-- ------------------------------------------------- -->
    <section class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 py-2">
            <span class="fw-semibold">Access events</span>
            <span class="badge text-bg-light border">{{ number_format($events->total()) }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Occurred</th>
                        <th>Mailbox</th>
                        <th>Event</th>
                        <th>Actor</th>
                        <th>Affected user</th>
                        <th>Operation / resource</th>
                        <th>Reason code</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                        <tr>
                            <td class="small text-nowrap">{{ $event->occurred_at?->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $event->account?->address ?? 'Unavailable' }}</td>
                            <td><span class="badge text-bg-light border">{{ str_replace('_', ' ', $event->event_type) }}</span></td>
                            <td>{{ $event->actor?->name ?? 'System/unavailable' }}</td>
                            <td>{{ $event->affectedUser?->name ?? '—' }}</td>
                            <td class="small">
                                {{ $event->operation ? str_replace('_', ' ', $event->operation) : '—' }}
                                @if($event->resource_type)
                                    <span class="text-muted">· {{ str_replace('_', ' ', $event->resource_type) }}{{ $event->resource_id ? ' #'.$event->resource_id : '' }}</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $event->reason_code ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted py-3 text-center">No access events in this scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($events->hasPages())
            <div class="card-footer py-2">{{ $events->withQueryString()->links() }}</div>
        @endif
    </section>
@endsection

@section('rightbar')
    <div class="alert alert-secondary small">
        This history contains account IDs, actors, operations, reason codes, and timestamps only. It never stores email subjects, addresses, filenames, snippets, bodies, raw source, search terms, attachments, or AI content.
    </div>
@endsection
