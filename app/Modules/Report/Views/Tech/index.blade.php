@extends('layouts.default_tech')

@section('title', 'Reports')

@section('pageHeader')
    <h1 class="h4 mb-0">Reports</h1>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Report filters -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Report Library">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="{{ route('tech.reports.index') }}" class="btn btn-sm {{ $activeDomain === '' ? 'btn-primary' : 'btn-outline-secondary' }}">
                All
            </a>
            @foreach($domains as $domain)
                <a href="{{ route('tech.reports.index', ['domain' => $domain]) }}" class="btn btn-sm {{ $activeDomain === $domain ? 'btn-primary' : 'btn-outline-secondary' }}">
                    {{ $domain }}
                </a>
            @endforeach
        </div>

        <div class="row g-3">
            @forelse($reports as $report)
                <div class="col-md-6 col-xl-4">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-start gap-3 mb-3">
                                <div class="rounded bg-light border p-2">
                                    <i class="{{ $report->icon }}"></i>
                                </div>
                                <div>
                                    <div class="small text-muted text-uppercase">{{ $report->domain }}</div>
                                    <h2 class="h6 mb-0">{{ $report->title }}</h2>
                                </div>
                            </div>
                            <p class="text-muted small flex-grow-1">{{ $report->description }}</p>
                            @if($report->tags !== [])
                                <div class="d-flex flex-wrap gap-1 mb-3">
                                    @foreach($report->tags as $tag)
                                        <span class="badge text-bg-light border">{{ $tag }}</span>
                                    @endforeach
                                </div>
                            @endif
                            <a href="{{ route($report->routeName) }}" class="btn btn-primary btn-sm align-self-start">Open report</a>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <div class="text-muted py-4">No reports are available for the selected filter.</div>
                </div>
            @endforelse
        </div>
    </x-card.default>
@endsection

@section('sidebar')
@endsection

@section('rightbar')
    <x-card.default title="Documentation / Help">
        <p class="small text-muted mb-3">
            The report library only lists reports that are implemented and ready to use.
        </p>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#reportHelpModal">
            Open help
        </button>
    </x-card.default>

    <div class="modal fade" id="reportHelpModal" tabindex="-1" aria-labelledby="reportHelpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="reportHelpModalLabel">Reports Help</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <p>
                        Reports are added here only after the underlying module has a reliable data model, filters,
                        permissions, tests, and documentation.
                    </p>
                    <p>
                        The Report domain owns this library and shared navigation. Each module owns the calculations
                        for its own reports.
                    </p>
                    <p class="mb-0">
                        More reports will be added after the module settings audit confirms which workflows and data
                        are ready for beta reporting.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection
