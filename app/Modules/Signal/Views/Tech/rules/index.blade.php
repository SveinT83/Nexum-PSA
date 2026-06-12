@extends('layouts.default_tech')

@section('title', 'Signal Rules')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">Signal Rules</h1>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('tech.admin.system.signals.index') }}" class="btn btn-sm btn-outline-secondary">Signals</a>
            @can('signal.rule.manage')
                <a href="{{ route('tech.admin.system.signals.rules.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    New Rule
                </a>
            @endcan
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Signal rules index -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Executions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr class="cursor-pointer" data-href="{{ route('tech.admin.system.signals.rules.show', $rule) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.admin.system.signals.rules.show', $rule) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">{{ $rule->name }}</a>
                                <div class="small text-muted">{{ $rule->description ?: '—' }}</div>
                            </td>
                            <td><span class="badge text-bg-{{ $rule->is_active ? 'success' : 'light' }} border">{{ $rule->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td>{{ $rule->priority }}</td>
                            <td>{{ $rule->executions_count }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No signal rules exist.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($rules->hasPages())
            <div class="card-footer">{{ $rules->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
