@extends('layouts.default_tech')

@section('title', 'Signals')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">Signals</h1>
        @can('signal.rule.manage')
            <a href="{{ route('tech.admin.system.signals.rules.index') }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-sliders" aria-hidden="true"></i>
                Rules
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Signal index -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Signal Feed</span>
            <span class="badge text-bg-light border">{{ $signals->total() }} total</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Signal</th>
                        <th>Source</th>
                        <th>Subject</th>
                        <th>Severity</th>
                        <th>Confidence</th>
                        <th>Rules</th>
                        <th>Occurred</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($signals as $signal)
                        <tr class="cursor-pointer" data-href="{{ route('tech.admin.system.signals.show', $signal) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.admin.system.signals.show', $signal) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">{{ str($signal->signal_type)->replace('_', ' ')->title() }}</a>
                                <div class="small text-muted">{{ $signal->summary ?: '—' }}</div>
                            </td>
                            <td>{{ ucfirst($signal->source_domain) }}</td>
                            <td>{{ $signal->contact?->display_name ?? $signal->client?->name ?? '—' }}</td>
                            <td><span class="badge text-bg-light border">{{ ucfirst($signal->severity) }}</span></td>
                            <td>{{ $signal->confidence }}%</td>
                            <td>{{ $signal->executions_count }}</td>
                            <td>{{ $signal->occurred_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No signals have been recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($signals->hasPages())
            <div class="card-footer">{{ $signals->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
