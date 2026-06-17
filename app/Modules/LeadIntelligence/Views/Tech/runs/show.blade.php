@extends('layouts.default_tech')

@section('title', 'Lead Research Run #'.$run->id)

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
        <h1 class="h4 mb-0">Lead Research Run #{{ $run->id }}</h1>
        <x-buttons.back :url="route('tech.lead-intelligence.runs.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Research run detail -->
    <!-- ------------------------------------------------- -->
    @if(session('success'))
        <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger py-2">{{ session('error') }}</div>
    @endif

    <div class="row g-3">
        <div class="col-xl-4">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h2 class="h6 mb-0">Run</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5">Status</dt>
                        <dd class="col-7 text-end">{{ Str::headline($run->status) }}</dd>
                        <dt class="col-5">Segment</dt>
                        <dd class="col-7 text-end">{{ $run->segment?->name ?? 'No segment' }}</dd>
                        <dt class="col-5">Tokens</dt>
                        <dd class="col-7 text-end">{{ number_format($run->tokens_used) }}</dd>
                        <dt class="col-5">Started</dt>
                        <dd class="col-7 text-end">{{ $run->started_at?->format('Y-m-d H:i') ?? '-' }}</dd>
                        <dt class="col-5">Finished</dt>
                        <dd class="col-7 text-end">{{ $run->finished_at?->format('Y-m-d H:i') ?? '-' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card shadow-sm h-100">
                <div class="card-header">
                    <h2 class="h6 mb-0">Summary</h2>
                </div>
                <div class="card-body">
                    <pre class="mb-0 small">{{ json_encode($run->summary_json ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-header">
            <h2 class="h6 mb-0">Source Evidence</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Source</th>
                        <th>Client</th>
                        <th>Contact</th>
                        <th class="text-end">Confidence</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($run->evidence as $evidence)
                        <tr>
                            <td>{{ $evidence->source_type }}</td>
                            <td>
                                @if($evidence->source_url)
                                    <a href="{{ $evidence->source_url }}" target="_blank" rel="noopener">{{ $evidence->source_title ?: Str::limit($evidence->source_url, 80) }}</a>
                                @else
                                    {{ $evidence->source_title ?: '-' }}
                                @endif
                            </td>
                            <td>{{ $evidence->client?->name ?? '-' }}</td>
                            <td>{{ $evidence->contact?->display_name ?? '-' }}</td>
                            <td class="text-end">{{ $evidence->confidence }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No evidence stored for this run.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.side-bar title="Lead Intelligence" :items="$leadNav" />
    <x-nav.sales-menu />
@endsection
