@extends('layouts.default_tech')

@section('title', 'Signal')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">{{ str($signal->signal_type)->replace('_', ' ')->title() }}</h1>
        <a href="{{ route('tech.admin.system.signals.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Signals
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Signal detail -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header">
            <span class="fw-semibold">Signal Details</span>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-md-3">Summary</dt>
                <dd class="col-md-9">{{ $signal->summary ?: '—' }}</dd>
                <dt class="col-md-3">Source</dt>
                <dd class="col-md-9">{{ $signal->source_domain }} / {{ $signal->source_type ?: '—' }} #{{ $signal->source_id ?: '—' }}</dd>
                <dt class="col-md-3">Subject</dt>
                <dd class="col-md-9">{{ $signal->contact?->display_name ?? $signal->client?->name ?? '—' }}</dd>
                <dt class="col-md-3">Payload</dt>
                <dd class="col-md-9"><pre class="small bg-light border rounded p-2 mb-0">{{ json_encode($signal->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></dd>
            </dl>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="fw-semibold">Rule Executions</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Rule</th>
                        <th>Status</th>
                        <th>Executed</th>
                        <th>Results</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($signal->executions as $execution)
                        <tr>
                            <td>{{ $execution->rule?->name ?? 'Deleted rule' }}</td>
                            <td><span class="badge text-bg-{{ $execution->status === 'executed' ? 'success' : 'danger' }}">{{ ucfirst($execution->status) }}</span></td>
                            <td>{{ $execution->executed_at?->format('Y-m-d H:i') }}</td>
                            <td><pre class="small bg-light border rounded p-2 mb-0">{{ json_encode($execution->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No rules executed for this signal.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
