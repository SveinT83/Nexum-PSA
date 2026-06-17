@extends('layouts.default_tech')

@section('title', 'Lead Segments')

@php
    $leadNav = [
        ['name' => 'Segments', 'route' => 'tech.lead-intelligence.segments.index', 'pattern' => 'tech.lead-intelligence.segments.*', 'icon' => 'bi-funnel'],
        ['name' => 'Research Runs', 'route' => 'tech.lead-intelligence.runs.index', 'pattern' => 'tech.lead-intelligence.runs.*', 'icon' => 'bi-search'],
        ['name' => 'Scan Ledger', 'route' => 'tech.lead-intelligence.scan-ledger.index', 'pattern' => 'tech.lead-intelligence.scan-ledger.*', 'icon' => 'bi-clock-history'],
        ['name' => 'Settings', 'route' => 'tech.admin.settings.lead-intelligence', 'pattern' => 'tech.admin.settings.lead-intelligence*', 'icon' => 'bi-sliders'],
    ];
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Lead Segments</h1>
        <a href="{{ route('tech.lead-intelligence.segments.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Segment
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Lead segment list -->
    <!-- ------------------------------------------------- -->
    @if(session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Schedule</th>
                        <th>Target roles</th>
                        <th>Runs</th>
                        <th class="text-end">Updated</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($segments as $segment)
                        <tr>
                            <td>
                                <a href="{{ route('tech.lead-intelligence.segments.edit', $segment) }}" class="fw-semibold">{{ $segment->name }}</a>
                                @if($segment->description)
                                    <div class="small text-muted">{{ Str::limit($segment->description, 120) }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $segment->enabled ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                                    {{ $segment->enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </td>
                            <td class="small">
                                @if($segment->schedule_enabled)
                                    <div>{{ Str::headline($segment->schedule_period ?: 'weekly') }} at {{ $segment->schedule_time ?: '08:00' }}</div>
                                    <div class="text-muted">Next: {{ $segment->next_run_at?->format('Y-m-d H:i') ?? 'not planned' }}</div>
                                @else
                                    <span class="text-muted">Manual</span>
                                @endif
                            </td>
                            <td class="small">{{ implode(', ', array_slice((array) $segment->target_roles_json, 0, 4)) ?: 'Not set' }}</td>
                            <td>{{ $segment->research_runs_count }}</td>
                            <td class="text-end small text-muted">{{ $segment->updated_at?->format('Y-m-d H:i') }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('tech.lead-intelligence.segments.run-now', $segment) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-play-fill me-1" aria-hidden="true"></i>Run Now
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No lead segments yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($segments->hasPages())
            <div class="card-footer">{{ $segments->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.side-bar title="Lead Intelligence" :items="$leadNav" />
    <x-nav.sales-menu />
@endsection
