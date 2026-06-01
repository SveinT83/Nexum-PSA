@extends('layouts.default_tech')

@section('title', 'Ticket SLA Report')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0">Ticket SLA Report</h1>
        <x-buttons.back url="{{ route('tech.reports.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Report filters -->
    <!-- ------------------------------------------------- -->
    <form method="GET" action="{{ route('tech.reports.tickets.sla') }}" class="card mb-3">
        <div class="card-body">
            <label for="period" class="form-label small text-muted text-uppercase fw-semibold">Period</label>
            <div class="input-group input-group-sm">
                <select id="period" name="period" class="form-select">
                    @foreach(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'all' => 'All time'] as $value => $label)
                        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-outline-secondary">Apply</button>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- SLA KPI cards -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3">
        @foreach([
            ['label' => 'Response overdue', 'value' => $summary['response_overdue'], 'class' => 'danger'],
            ['label' => 'Resolve overdue', 'value' => $summary['resolve_overdue'], 'class' => 'danger'],
            ['label' => 'Responded within SLA', 'value' => $summary['responded_within_sla'], 'class' => 'success'],
            ['label' => 'Resolved within SLA', 'value' => $summary['resolved_within_sla'], 'class' => 'success'],
            ['label' => 'Responded late', 'value' => $summary['responded_late'], 'class' => 'warning'],
            ['label' => 'Resolved late', 'value' => $summary['resolved_late'], 'class' => 'warning'],
        ] as $metric)
            <div class="col-md-4 col-xl-2">
                <div class="card h-100 border-{{ $metric['class'] }}">
                    <div class="card-body py-3">
                        <div class="small text-muted text-uppercase">{{ $metric['label'] }}</div>
                        <div class="display-6 fw-semibold text-{{ $metric['class'] }}">{{ $metric['value'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Current overdue tickets -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Current SLA Risk">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Client</th>
                        <th>Priority</th>
                        <th>Owner</th>
                        <th>Response due</th>
                        <th>Resolve due</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($overdueTickets as $ticket)
                        <tr>
                            <td><a href="{{ route('tech.tickets.show', $ticket) }}">{{ $ticket->ticket_key }}</a></td>
                            <td>{{ $ticket->client?->name ?? '—' }}</td>
                            <td>{{ $ticket->priority?->name ?? '—' }}</td>
                            <td>{{ $ticket->owner?->name ?? 'Unassigned' }}</td>
                            <td class="{{ $ticket->first_response_due_at?->isPast() && ! $ticket->first_responded_at ? 'text-danger fw-semibold' : '' }}">
                                {{ $ticket->first_response_due_at?->format('Y-m-d H:i') ?? '—' }}
                            </td>
                            <td class="{{ $ticket->resolve_due_at?->isPast() && ! $ticket->resolved_at ? 'text-danger fw-semibold' : '' }}">
                                {{ $ticket->resolve_due_at?->format('Y-m-d H:i') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">No overdue SLA tickets found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card.default>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

@section('rightbar')
    <x-card.default title="Scope">
        <p class="small text-muted mb-0">
            This report uses stored SLA timestamps on tickets. Business-hours recalculation is intentionally out of scope for this foundation.
        </p>
    </x-card.default>
@endsection
