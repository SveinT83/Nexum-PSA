@extends('layouts.default_tech')

@section('title', 'Ticket Rule executions')

@section('pageHeader')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h1 class="mb-0">Ticket Rule executions</h1>
            <p class="text-muted mb-0">Internal immutable execution evidence.</p>
        </div>
        @if($canManageRules)
            <x-buttons.back url="{{ route('tech.admin.settings.tickets.rules') }}">Rules</x-buttons.back>
        @endif
    </div>
@endsection

@section('content')
    @if(! $outcomeControlsAvailable)
        <div class="alert alert-secondary">Result filtering and result/duration sorting are unavailable because restricted execution evidence exists.</div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Execution Filters -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h6 mb-0">Filter executions</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('tech.admin.settings.tickets.rules.executions.index') }}">
                <div class="row g-3">
                    <div class="col-12 col-lg-4">
                        <label for="rule_id" class="form-label">Rule</label>
                        <select id="rule_id" name="rule_id" class="form-select">
                            <option value="">All rules</option>
                            @foreach($ruleOptions as $option)
                                <option value="{{ $option->id }}" @selected((string) ($filters['rule_id'] ?? '') === (string) $option->id)>
                                    {{ $option->name }}{{ $option->deleted_at ? ' (deleted)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @if($ruleOptionsOmittedCount > 0)
                            <div class="form-text">
                                Showing a bounded rule list; {{ number_format($ruleOptionsOmittedCount) }} additional rules are omitted. A currently selected rule remains available; use the other filters to narrow the ledger.
                            </div>
                        @endif
                    </div>
                    <div class="col-12 col-md-6 col-lg-4">
                        <label for="ticket" class="form-label">Ticket key or ID</label>
                        <input
                            id="ticket"
                            name="ticket"
                            type="search"
                            value="{{ $filters['ticket'] ?? '' }}"
                            class="form-control"
                            maxlength="64"
                            placeholder="TD-2026-000123"
                        >
                    </div>
                    <div class="col-12 col-md-6 col-lg-4">
                        <label for="event" class="form-label">Root event</label>
                        <select id="event" name="event" class="form-select">
                            <option value="">All events</option>
                            @foreach($eventOptions as $event)
                                <option value="{{ $event }}" @selected(($filters['event'] ?? '') === $event)>
                                    {{ \Illuminate\Support\Str::headline($event) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label for="result" class="form-label">Result</label>
                        <select id="result" name="result" class="form-select" @disabled(! $outcomeControlsAvailable)>
                            <option value="">All results</option>
                            @foreach($resultOptions as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['result'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label for="from" class="form-label">From</label>
                        <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label for="to" class="form-label">To</label>
                        <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-6 col-lg-3">
                        <label for="sort" class="form-label">Sort by</label>
                        <select id="sort" name="sort" class="form-select">
                            <option value="started_at" @selected(($filters['sort'] ?? 'started_at') === 'started_at')>Started</option>
                            @if($outcomeControlsAvailable)
                                <option value="status" @selected(($filters['sort'] ?? '') === 'status')>Result</option>
                            @endif
                            <option value="event" @selected(($filters['sort'] ?? '') === 'event')>Event</option>
                            @if($outcomeControlsAvailable)
                                <option value="duration" @selected(($filters['sort'] ?? '') === 'duration')>Duration</option>
                            @endif
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label for="direction" class="form-label">Direction</label>
                        <select id="direction" name="direction" class="form-select">
                            <option value="desc" @selected(($filters['direction'] ?? 'desc') === 'desc')>Descending</option>
                            <option value="asc" @selected(($filters['direction'] ?? '') === 'asc')>Ascending</option>
                        </select>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary">Apply filters</button>
                    <a href="{{ route('tech.admin.settings.tickets.rules.executions.index') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Execution Ledger -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <h2 class="h6 mb-0">Execution ledger</h2>
            <span class="text-muted small">{{ number_format($runs->total()) }} results</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Started</th>
                        <th>Ticket</th>
                        <th>Event</th>
                        <th>Rules</th>
                        <th>Result</th>
                        <th class="text-end">Duration</th>
                        <th class="text-end">Evidence</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($runs as $run)
                        <tr>
                            <td class="text-nowrap">
                                <div>{{ $run['started_at']?->format('Y-m-d H:i:s') ?? '—' }}</div>
                                @if($run['mode'] !== 'runtime' || $run['attempt_number'] > 1)
                                    <div class="small text-muted">{{ \Illuminate\Support\Str::headline($run['mode']) }} · attempt {{ $run['attempt_number'] }}</div>
                                @endif
                            </td>
                            <td>
                                @if($run['ticket_available'])
                                    <a href="{{ route('tech.tickets.show', ['ticket' => $run['ticket_id']]) }}" class="text-decoration-none">
                                        {{ $run['ticket_key'] }}
                                    </a>
                                @else
                                    <span class="text-muted">{{ $run['ticket_key'] }}</span>
                                @endif
                            </td>
                            <td>
                                <div>{{ $run['root_event_label'] }}</div>
                                <code class="small">{{ $run['root_event'] }}</code>
                            </td>
                            <td>
                                @if($run['restricted_evidence'])
                                    <span class="text-muted">Restricted evidence</span>
                                @else
                                    @forelse($run['rule_names'] as $name)
                                        <div class="small">{{ $name }}</div>
                                    @empty
                                        <span class="text-muted">No evaluated rules</span>
                                    @endforelse
                                    @if($run['rule_names_omitted'] > 0)
                                        <div class="small text-muted">+{{ $run['rule_names_omitted'] }} more</div>
                                    @endif
                                @endif
                            </td>
                            <td>
                                <span class="badge text-bg-{{ $run['status_class'] }}">{{ $run['status_label'] }}</span>
                            </td>
                            <td class="text-end text-nowrap">
                                {{ $run['duration_ms'] === null ? '—' : number_format($run['duration_ms']).' ms' }}
                            </td>
                            <td class="text-end text-nowrap small">
                                @if($run['restricted_evidence'])
                                    <span class="text-muted">Restricted evidence</span>
                                @else
                                    {{ $run['event_count'] }} events · {{ $run['action_count'] }} actions
                                @endif
                            </td>
                            <td class="text-end">
                                <a
                                    href="{{ route('tech.admin.settings.tickets.rules.executions.show', ['run' => $run['id']]) }}"
                                    class="btn btn-sm btn-outline-primary"
                                >
                                    Inspect
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">No executions match these filters.</td>
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
    <x-nav.admin-menu group="tickets" />
@endsection

@section('rightbar')
    <x-card.default title="Safe evidence">
        <p class="small text-muted mb-2">This ledger shows an explicit allowlist of operational evidence. Raw event and action JSON is never rendered.</p>
        <p class="small text-muted mb-0">Custom Field values remain redacted to type, presence, length, and fingerprints.</p>
    </x-card.default>
@endsection
