@extends('layouts.default_tech')

@section('title', 'RMM Alert Rules')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">RMM Alert Rules</h1>
    </div>
    <div class="col-auto">
        <x-buttons.back :url="route('tech.admin.system.integrations.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <!-- Runtime boundary -->
    <div class="alert alert-info d-flex gap-2 align-items-start" role="note">
        <i class="bi bi-shield-check mt-1" aria-hidden="true"></i>
        <div>
            Conditions in one rule use <strong>AND</strong>. Rules run only for a future new or reopened alert occurrence;
            an unchanged 15-minute RMM heartbeat and alert resolution do not create work.
        </div>
    </div>

    <!-- Rule list -->
    <div class="card shadow-sm mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">Rules <span class="badge text-bg-light border ms-1">{{ $rules->count() }}</span></h2>
            <x-buttons.addlink :url="route('tech.admin.system.integrations.rmm-alert-rules.create')" class="mb-0">New rule</x-buttons.addlink>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        <th scope="col">Rule</th>
                        <th scope="col">Conditions</th>
                        <th scope="col">Ordered actions</th>
                        <th scope="col">Latest result</th>
                        <th scope="col" class="text-end">Manage</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        @php($latest = $rule->latestExecution)
                        <tr>
                            <td class="text-nowrap">{{ $rule->priority }}</td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <strong>{{ $rule->name }}</strong>
                                    <span class="badge {{ $rule->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $rule->is_active ? 'Active' : 'Disabled' }}
                                    </span>
                                </div>
                                <div class="small text-muted">Revision {{ $rule->revision }} · {{ $rule->executions_count }} evaluations</div>
                                @if($rule->stop_processing)
                                    <div class="small text-muted">Stops lower rules after success</div>
                                @endif
                            </td>
                            <td class="small" style="min-width: 16rem;">{{ $definitions->conditionSummary($rule->conditions ?? []) }}</td>
                            <td class="small" style="min-width: 14rem;">{{ $definitions->actionSummary($rule->actions ?? []) }}</td>
                            <td>
                                @if($latest)
                                    <span class="badge {{ in_array($latest->status, ['completed', 'ignored'], true) ? 'text-bg-success' : ($latest->status === 'failed' ? 'text-bg-danger' : 'text-bg-light border') }}">
                                        {{ str_replace('_', ' ', $latest->status) }}
                                    </span>
                                    <div class="small text-muted">{{ $latest->completed_at?->diffForHumans() ?? $latest->created_at?->diffForHumans() }}</div>
                                @else
                                    <span class="text-muted small">Never evaluated</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('tech.admin.system.integrations.rmm-alert-rules.edit', $rule) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                @if($rule->is_active)
                                    <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Disable this rule before deleting it.">Delete</button>
                                @else
                                    <form action="{{ route('tech.admin.system.integrations.rmm-alert-rules.destroy', $rule) }}" method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete this RMM Alert Rule? Existing execution evidence will remain.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No RMM Alert Rules exist yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Immutable execution history -->
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Recent execution audit</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Occurrence</th>
                        <th scope="col">Rule snapshot</th>
                        <th scope="col">Result</th>
                        <th scope="col">Targets</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($executions as $execution)
                        <tr>
                            <td class="text-nowrap">{{ $execution->started_at?->format('Y-m-d H:i:s') }}</td>
                            <td>
                                <div>{{ $execution->occurrence?->title ?? 'Deleted occurrence' }}</div>
                                <div class="small text-muted">#{{ $execution->occurrence?->sequence }} · {{ $execution->occurrence?->severity }} · {{ $execution->occurrence?->integration_type }}</div>
                                <code class="small">{{ $execution->occurrence?->fingerprint }}</code>
                            </td>
                            <td>
                                <div>{{ $execution->rule_name }}</div>
                                <div class="small text-muted">Revision {{ $execution->rule_revision }} · {{ $execution->matched ? 'Matched' : 'Not matched' }}</div>
                            </td>
                            <td>
                                <span class="badge {{ $execution->status === 'failed' ? 'text-bg-danger' : (in_array($execution->status, ['completed', 'ignored'], true) ? 'text-bg-success' : 'text-bg-light border') }}">
                                    {{ str_replace('_', ' ', $execution->status) }}
                                </span>
                                @if($execution->error)
                                    <div class="small text-danger mt-1">{{ $execution->error }}</div>
                                @endif
                            </td>
                            <td>
                                @forelse($execution->workItems as $item)
                                    <div class="small">
                                        {{ str_replace('_', ' ', $item->action_type) }}:
                                        @if($item->target_id)
                                            {{ class_basename($item->target_type) }} #{{ $item->target_id }}
                                        @else
                                            {{ data_get($item->metadata, 'result', 'recorded') }}
                                        @endif
                                    </div>
                                @empty
                                    <span class="small text-muted">No target writes</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No RMM rule executions recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($executions->hasPages())
            <div class="card-footer">{{ $executions->links() }}</div>
        @endif
    </div>
@endsection
