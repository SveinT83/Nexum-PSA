@extends('layouts.default_tech')

@section('title', $rule['name'])

@section('pageHeader')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h1 class="mb-0">{{ $rule['name'] }}</h1>
                @if($rule['deleted_at'])
                    <span class="badge text-bg-danger">Deleted</span>
                @elseif($rule['is_active'])
                    <span class="badge text-bg-success">Active</span>
                @else
                    <span class="badge text-bg-secondary">Disabled</span>
                @endif
            </div>
            <p class="text-muted mb-0">Ticket Rule #{{ $rule['id'] }} · immutable version history</p>
        </div>
        <div class="d-flex gap-2">
            <x-buttons.back url="{{ route('tech.admin.settings.tickets.rules') }}">Rules</x-buttons.back>
            @if(!$rule['deleted_at'])
                <a href="{{ route('tech.admin.settings.tickets.rules.edit', $ruleModel) }}" class="btn btn-sm btn-outline-primary">Edit rule</a>
            @endif
            @if($canViewExecutions)
                <a
                    href="{{ route('tech.admin.settings.tickets.rules.executions.index', ['rule_id' => $rule['id']]) }}"
                    class="btn btn-sm btn-outline-secondary"
                >
                    All executions
                </a>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Rule Summary -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Rule summary</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12">
                    <div class="small text-muted">Description</div>
                    <div>{{ $rule['description'] ?: 'No description' }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-muted">Lifecycle</div>
                    <div>{{ \Illuminate\Support\Str::headline($rule['lifecycle_status']) }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-muted">Compatibility</div>
                    <div>{{ \Illuminate\Support\Str::headline($rule['compatibility_status']) }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-muted">Weight</div>
                    <div>{{ $rule['weight'] }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-muted">Flow</div>
                    <div>{{ $rule['stop_processing'] ? 'Stop after match' : 'Continue after match' }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-muted">Published version</div>
                    <div>{{ $rule['published_version_id'] ? '#'.$rule['published_version_id'] : 'None' }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-muted">Published</div>
                    <div>{{ $rule['published_at']?->format('Y-m-d H:i:s') ?? '—' }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-muted">Immutable versions</div>
                    <div>{{ $rule['version_count'] }}</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-muted">Execution evidence</div>
                    <div>{{ number_format($rule['execution_count']) }} evaluations</div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="small text-muted">Legacy logs</div>
                    <div>{{ number_format($rule['legacy_log_count']) }} read-only records</div>
                </div>
                @if($rule['compatibility_reason_code'])
                    <div class="col-12">
                        <div class="small text-muted">Compatibility reason</div>
                        <code>{{ $rule['compatibility_reason_code'] }}</code>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Immutable Version History -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h6 mb-0">Immutable versions</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Version</th>
                        <th>Trigger</th>
                        <th>Conditions</th>
                        <th>Then actions</th>
                        <th>Else actions</th>
                        <th>Published</th>
                        <th>Checksum</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rule['versions'] as $version)
                        <tr>
                            <td>
                                <div class="fw-semibold">v{{ $version['version_number'] }}</div>
                                <div class="small text-muted">schema {{ $version['schema_version'] }} · {{ \Illuminate\Support\Str::headline($version['status']) }}</div>
                            </td>
                            <td>
                                <div>{{ $version['trigger_label'] }}</div>
                                <code class="small">{{ $version['trigger'] }}</code>
                            </td>
                            <td>{{ $version['condition_count'] }}</td>
                            <td>
                                @forelse($version['then_actions'] as $action)
                                    <div class="small">{{ $action }}</div>
                                @empty
                                    <span class="text-muted">None</span>
                                @endforelse
                            </td>
                            <td>
                                @forelse($version['else_actions'] as $action)
                                    <div class="small">{{ $action }}</div>
                                @empty
                                    <span class="text-muted">None</span>
                                @endforelse
                            </td>
                            <td>
                                <div>{{ $version['published_at']?->format('Y-m-d H:i:s') ?? '—' }}</div>
                                @if($version['published_by'])
                                    <div class="small text-muted">User #{{ $version['published_by'] }}</div>
                                @endif
                            </td>
                            <td><code title="{{ $version['definition_checksum'] }}">{{ \Illuminate\Support\Str::limit($version['definition_checksum'], 16, '…') }}</code></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No immutable versions exist for this rule.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Recent Execution History -->
    <!-- ------------------------------------------------- -->
    @if($canViewExecutions && $recentRuns)
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-3">
                <h2 class="h6 mb-0">Recent executions</h2>
                <a href="{{ route('tech.admin.settings.tickets.rules.executions.index', ['rule_id' => $rule['id']]) }}" class="small">Open filtered ledger</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Started</th><th>Ticket</th><th>Event</th><th>Result</th><th class="text-end">Duration</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($recentRuns as $run)
                            <tr>
                                <td>{{ $run['started_at']?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                <td>{{ $run['ticket_key'] }}</td>
                                <td>{{ $run['root_event_label'] }}</td>
                                <td><span class="badge text-bg-{{ $run['status_class'] }}">{{ $run['status_label'] }}</span></td>
                                <td class="text-end">{{ $run['duration_ms'] === null ? '—' : number_format($run['duration_ms']).' ms' }}</td>
                                <td class="text-end">
                                    <a href="{{ route('tech.admin.settings.tickets.rules.executions.show', ['run' => $run['id']]) }}" class="btn btn-sm btn-outline-primary">Inspect</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">This rule has no execution evidence.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($recentRuns->hasPages())
                <div class="card-footer">{{ $recentRuns->links() }}</div>
            @endif
        </div>
    @endif
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('rightbar')
    <x-card.default title="Read-only detail">
        <p class="small text-muted mb-2">This page summarizes immutable version metadata without rendering raw definition JSON.</p>
        <p class="small text-muted mb-2">Execution evidence has its own permission and also requires ordinary Ticket view access.</p>
        <p class="small text-muted mb-0">Legacy logs are historical read-only records and are not equivalent to immutable v2 execution evidence.</p>
    </x-card.default>
@endsection
