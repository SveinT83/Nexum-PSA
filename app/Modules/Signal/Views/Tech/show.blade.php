@extends('layouts.default_tech')

@section('title', 'Signal')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h4 mb-0">{{ str($signal->signal_type)->replace('_', ' ')->title() }}</h1>
            <div class="small text-muted">{{ $signal->occurred_at?->format('Y-m-d H:i') }}</div>
        </div>
        <a href="{{ route('tech.admin.system.signals.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Back
        </a>
    </div>
@endsection

@section('content')
    @if(session('status'))
        <div class="alert alert-info py-2">{{ session('status') }}</div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Signal detail -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header"><span class="fw-semibold">Signal Details</span></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-md-3">Summary</dt>
                <dd class="col-md-9">{{ $signal->summary ?: '—' }}</dd>
                <dt class="col-md-3">Source</dt>
                <dd class="col-md-9">{{ $signal->source_domain }} / {{ $signal->source_type ?: '—' }} #{{ $signal->source_id ?: '—' }}</dd>
                <dt class="col-md-3">Subject</dt>
                <dd class="col-md-9">{{ $signal->contact?->display_name ?? $signal->client?->name ?? '—' }}</dd>
                <dt class="col-md-3">Severity / confidence</dt>
                <dd class="col-md-9"><span class="badge text-bg-light border">{{ ucfirst($signal->severity) }}</span> {{ $signal->confidence }}%</dd>
                <dt class="col-md-3">Payload</dt>
                <dd class="col-md-9"><pre class="small bg-light border rounded p-2 mb-0">{{ json_encode($signal->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></dd>
            </dl>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Rule execution and retry log -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header"><span class="fw-semibold">Rule Executions</span></div>
        <div class="card-body vstack gap-3">
            @forelse($signal->executions as $execution)
                @php
                    $statusClass = $execution->status === 'executed' ? 'success' : 'danger';
                    $isRoot = ! $execution->retry_of_execution_id;
                @endphp
                <div class="border rounded">
                    <div class="d-flex flex-wrap align-items-center gap-2 p-2 bg-body-tertiary border-bottom">
                        <div>
                            <div class="fw-semibold">{{ $execution->rule?->name ?? 'Deleted rule' }}</div>
                            <div class="small text-muted">
                                Attempt {{ $execution->attempt }}
                                @if($execution->retry_of_execution_id)
                                    · retry of execution #{{ $execution->retry_of_execution_id }}
                                @endif
                                · {{ $execution->executed_at?->format('Y-m-d H:i:s') }}
                            </div>
                        </div>
                        <span class="badge text-bg-{{ $statusClass }} ms-auto">{{ str($execution->status)->replace('_', ' ')->title() }}</span>
                        @can('signal.action.execute')
                            @if($isRoot && $execution->rule)
                                @if($execution->hasRetryableActions())
                                    <form method="POST" action="{{ route('tech.admin.system.signals.executions.retry', [$signal, $execution]) }}">
                                        @csrf
                                        <input type="hidden" name="mode" value="failed">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                            Retry failed / unstarted
                                        </button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('tech.admin.system.signals.executions.retry', [$signal, $execution]) }}" onsubmit="return confirm('Run the complete rule again? Existing side effects will be skipped where they already exist.');">
                                    @csrf
                                    <input type="hidden" name="mode" value="all">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Advanced: run every action again with idempotency protection">
                                        Run whole rule again
                                    </button>
                                </form>
                            @endif
                        @endcan
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr><th style="width: 5rem;">Order</th><th>Action</th><th>Status</th><th>Result</th></tr>
                            </thead>
                            <tbody>
                                @forelse((array) $execution->results as $resultIndex => $result)
                                    @php
                                        $result = is_array($result) ? $result : [];
                                        $resultStatus = $result['status'] ?? 'unknown';
                                        $resultClass = match($resultStatus) {
                                            'done', 'queued' => 'success',
                                            'skipped' => 'secondary',
                                            'not_run' => 'warning',
                                            'failed' => 'danger',
                                            default => 'light',
                                        };
                                        $actionIndex = (int) ($result['action_index'] ?? $resultIndex);
                                        $details = collect($result)->except(['action_index', 'type', 'status'])->filter(fn ($value) => filled($value));
                                    @endphp
                                    <tr>
                                        <td>#{{ $actionIndex + 1 }}</td>
                                        <td>{{ str($result['type'] ?? data_get($execution->actions, $actionIndex.'.type', 'unknown'))->replace('_', ' ')->title() }}</td>
                                        <td><span class="badge text-bg-{{ $resultClass }}">{{ str($resultStatus)->replace('_', ' ')->title() }}</span></td>
                                        <td class="small">
                                            @if($details->has('message'))
                                                {{ $details->get('message') }}
                                            @elseif($details->isNotEmpty())
                                                <code>{{ $details->map(fn ($value, $key) => $key.'='.(is_scalar($value) ? $value : json_encode($value)))->implode(', ') }}</code>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-3">No action results were recorded.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($execution->error)
                        <div class="alert alert-danger rounded-0 rounded-bottom mb-0 py-2 small">{{ $execution->error }}</div>
                    @endif
                </div>
            @empty
                <div class="text-center text-muted py-4">No rules executed for this signal.</div>
            @endforelse
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
