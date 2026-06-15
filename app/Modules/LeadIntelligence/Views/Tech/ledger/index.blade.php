@extends('layouts.default_tech')

@section('title', 'Lead Scan Ledger')

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
        <h1 class="h4 mb-0">Lead Scan Ledger</h1>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Scan ledger filters and list -->
    <!-- ------------------------------------------------- -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('tech.lead-intelligence.scan-ledger.index') }}" class="row g-3 align-items-end">
                <div class="col-lg-4">
                    <label for="domain" class="form-label">Domain</label>
                    <input type="text" id="domain" name="domain" class="form-control" value="{{ $filters['domain'] }}">
                </div>
                <div class="col-lg-3">
                    <label for="status" class="form-label">Status</label>
                    <input type="text" id="status" name="status" class="form-control" value="{{ $filters['status'] }}">
                </div>
                <div class="col-lg-3">
                    <input type="hidden" name="due_only" value="0">
                    <div class="form-check mb-2">
                        <input type="checkbox" id="due_only" name="due_only" value="1" class="form-check-input" @checked($filters['due_only'])>
                        <label for="due_only" class="form-check-label">Due only</label>
                    </div>
                </div>
                <div class="col-lg-2 text-lg-end">
                    <button type="submit" class="btn btn-outline-secondary">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Domain</th>
                        <th>Org no</th>
                        <th>Status</th>
                        <th>Last scanned</th>
                        <th>Next scan after</th>
                        <th class="text-end">Usage</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($entries as $entry)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $entry->domain ?? '-' }}</div>
                                @if($entry->url)
                                    <div class="small text-muted">{{ Str::limit($entry->url, 90) }}</div>
                                @endif
                            </td>
                            <td>{{ $entry->org_no ?? '-' }}</td>
                            <td><span class="badge bg-secondary-subtle text-secondary">{{ $entry->status ?? 'unknown' }}</span></td>
                            <td>{{ $entry->last_scanned_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>
                                @if($entry->next_scan_after && $entry->next_scan_after->isFuture())
                                    <span class="text-muted">{{ $entry->next_scan_after->format('Y-m-d H:i') }}</span>
                                @else
                                    <span class="text-success">{{ $entry->next_scan_after?->format('Y-m-d H:i') ?? 'Due now' }}</span>
                                @endif
                            </td>
                            <td class="text-end small">
                                {{ number_format($entry->pages_scanned) }} pages<br>
                                {{ number_format($entry->tokens_used) }} tokens
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No scan ledger entries match the filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($entries->hasPages())
            <div class="card-footer">{{ $entries->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.side-bar title="Lead Intelligence" :items="$leadNav" />
    <x-nav.sales-menu />
@endsection
