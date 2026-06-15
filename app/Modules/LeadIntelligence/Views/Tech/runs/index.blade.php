@extends('layouts.default_tech')

@section('title', 'Lead Research Runs')

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
        <h1 class="h4 mb-0">Lead Research Runs</h1>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Research run planning -->
    <!-- ------------------------------------------------- -->
    @if(session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-header">
            <h2 class="h6 mb-0">Plan Research Run</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('tech.lead-intelligence.runs.store') }}" class="row g-3 align-items-end">
                @csrf
                <div class="col-lg-5">
                    <label for="lead_segment_id" class="form-label">Segment</label>
                    <select id="lead_segment_id" name="lead_segment_id" class="form-select @error('lead_segment_id') is-invalid @enderror">
                        <option value="">No segment</option>
                        @foreach($segments as $segment)
                            <option value="{{ $segment->id }}" @selected(old('lead_segment_id') == $segment->id)>{{ $segment->name }}</option>
                        @endforeach
                    </select>
                    @error('lead_segment_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-lg-3">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select @error('status') is-invalid @enderror">
                        @foreach($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', 'draft') === $status)>{{ Str::headline($status) }}</option>
                        @endforeach
                    </select>
                    @error('status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-lg-4 text-lg-end">
                    <button type="submit" class="btn btn-primary">Save Planned Run</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Segment</th>
                        <th>Status</th>
                        <th>Tokens</th>
                        <th class="text-end">Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($runs as $run)
                        <tr>
                            <td><a href="{{ route('tech.lead-intelligence.runs.show', $run) }}">#{{ $run->id }}</a></td>
                            <td>{{ $run->segment?->name ?? 'No segment' }}</td>
                            <td><span class="badge bg-secondary-subtle text-secondary">{{ Str::headline($run->status) }}</span></td>
                            <td>{{ number_format($run->tokens_used) }}</td>
                            <td class="text-end small text-muted">{{ $run->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No research runs yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($runs->hasPages())
            <div class="card-footer">{{ $runs->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.side-bar title="Lead Intelligence" :items="$leadNav" />
    <x-nav.sales-menu />
@endsection
