@extends('layouts.default_tech')

@section('title', 'Signals')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h4 mb-0">Signals</h1>
            <div class="small text-muted">Operational events and automation history</div>
        </div>
        @can('signal.rule.manage')
            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('tech.admin.system.signals.rules.index') }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-sliders" aria-hidden="true"></i>
                    Rules
                </a>
                <a href="{{ route('tech.admin.system.signals.settings.edit') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-gear" aria-hidden="true"></i>
                    Settings
                </a>
            </div>
        @endcan
    </div>
@endsection

@section('content')
    @php
        $sortUrl = function (string $field) use ($sort, $direction): string {
            $nextDirection = $sort === $field && $direction === 'asc' ? 'desc' : 'asc';
            return route('tech.admin.system.signals.index', array_merge(request()->query(), [
                'sort' => $field,
                'direction' => $nextDirection,
            ]));
        };
        $sortIcon = fn (string $field): string => $sort === $field
            ? ($direction === 'asc' ? 'bi-sort-up' : 'bi-sort-down')
            : 'bi-arrow-down-up';
        $hasFilters = collect(request()->except(['range', 'sort', 'direction', 'page']))->filter(fn ($value) => filled($value))->isNotEmpty() || $range !== '30';
    @endphp

    <!-- ------------------------------------------------- -->
    <!-- Signal feed filters -->
    <!-- ------------------------------------------------- -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('tech.admin.system.signals.index') }}" class="row g-2 align-items-end">
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="direction" value="{{ $direction }}">
                <div class="col-lg-4">
                    <label for="signal_search" class="form-label">Search</label>
                    <input type="search" id="signal_search" name="q" class="form-control" value="{{ request('q') }}" placeholder="Summary, type, source, client or contact">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="signal_range" class="form-label">Period</label>
                    <select id="signal_range" name="range" class="form-select" data-signal-range>
                        <option value="7" @selected($range === '7')>Last 7 days</option>
                        <option value="30" @selected($range === '30')>Last 30 days</option>
                        <option value="90" @selected($range === '90')>Last 90 days</option>
                        <option value="custom" @selected($range === 'custom')>Custom dates</option>
                        <option value="all" @selected($range === 'all')>All history</option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2 {{ $range === 'custom' ? '' : 'd-none' }}" data-signal-custom-date>
                    <label for="signal_from" class="form-label">From</label>
                    <input type="date" id="signal_from" name="from" class="form-control" value="{{ request('from') }}">
                </div>
                <div class="col-sm-6 col-lg-2 {{ $range === 'custom' ? '' : 'd-none' }}" data-signal-custom-date>
                    <label for="signal_to" class="form-label">To</label>
                    <input type="date" id="signal_to" name="to" class="form-control" value="{{ request('to') }}">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="signal_source" class="form-label">Source</label>
                    <select id="signal_source" name="source_domain" class="form-select">
                        <option value="">All sources</option>
                        @foreach($sourceOptions as $option)
                            <option value="{{ $option }}" @selected(request('source_domain') === $option)>{{ str($option)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="signal_type" class="form-label">Type</label>
                    <select id="signal_type" name="signal_type" class="form-select">
                        <option value="">All types</option>
                        @foreach($typeOptions as $option)
                            <option value="{{ $option }}" @selected(request('signal_type') === $option)>{{ str($option)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="signal_severity" class="form-label">Severity</label>
                    <select id="signal_severity" name="severity" class="form-select">
                        <option value="">All severities</option>
                        @foreach($severityOptions as $option)
                            <option value="{{ $option }}" @selected(request('severity') === $option)>{{ ucfirst($option) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="signal_status" class="form-label">Status</label>
                    <select id="signal_status" name="status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach($statusOptions as $option)
                            <option value="{{ $option }}" @selected(request('status') === $option)>{{ str($option)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-auto ms-lg-auto d-flex gap-2">
                    @if($hasFilters)
                        <a href="{{ route('tech.admin.system.signals.index') }}" class="btn btn-outline-secondary">Reset</a>
                    @endif
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Signal feed -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Signal Feed</span>
            <span class="badge text-bg-light border">{{ $signals->total() }} found</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        @foreach([
                            'signal_type' => 'Signal',
                            'source_domain' => 'Source',
                        ] as $field => $label)
                            <th><a href="{{ $sortUrl($field) }}" class="d-inline-flex align-items-center gap-1 text-reset text-decoration-none">{{ $label }} <i class="bi {{ $sortIcon($field) }} small text-muted" aria-hidden="true"></i></a></th>
                        @endforeach
                        <th>Subject</th>
                        <th><a href="{{ $sortUrl('severity') }}" class="d-inline-flex align-items-center gap-1 text-reset text-decoration-none">Severity <i class="bi {{ $sortIcon('severity') }} small text-muted" aria-hidden="true"></i></a></th>
                        <th><a href="{{ $sortUrl('confidence') }}" class="d-inline-flex align-items-center gap-1 text-reset text-decoration-none">Confidence <i class="bi {{ $sortIcon('confidence') }} small text-muted" aria-hidden="true"></i></a></th>
                        <th><a href="{{ $sortUrl('executions_count') }}" class="d-inline-flex align-items-center gap-1 text-reset text-decoration-none">Rules <i class="bi {{ $sortIcon('executions_count') }} small text-muted" aria-hidden="true"></i></a></th>
                        <th><a href="{{ $sortUrl('occurred_at') }}" class="d-inline-flex align-items-center gap-1 text-reset text-decoration-none">Occurred <i class="bi {{ $sortIcon('occurred_at') }} small text-muted" aria-hidden="true"></i></a></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($signals as $signal)
                        <tr class="cursor-pointer" data-href="{{ route('tech.admin.system.signals.show', $signal) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.admin.system.signals.show', $signal) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">{{ str($signal->signal_type)->replace('_', ' ')->title() }}</a>
                                <div class="small text-muted">{{ $signal->summary ?: '—' }}</div>
                            </td>
                            <td>{{ str($signal->source_domain)->replace('_', ' ')->title() }}</td>
                            <td>{{ $signal->contact?->display_name ?? $signal->client?->name ?? '—' }}</td>
                            <td><span class="badge text-bg-light border">{{ ucfirst($signal->severity) }}</span></td>
                            <td>{{ $signal->confidence }}%</td>
                            <td>{{ $signal->executions_count }}</td>
                            <td>{{ $signal->occurred_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No signals match these filters.</td>
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

@section('scripts')
    <script>
        document.querySelector('[data-signal-range]')?.addEventListener('change', function (event) {
            document.querySelectorAll('[data-signal-custom-date]').forEach(function (element) {
                element.classList.toggle('d-none', event.target.value !== 'custom');
            });
        });
    </script>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
