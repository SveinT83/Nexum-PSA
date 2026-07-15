@extends('layouts.default_tech')

@section('title', $rule->name)

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">{{ $rule->name }}</h1>
        <a href="{{ route('tech.admin.system.signals.rules.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Back
        </a>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Signal rule detail and edit -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif

    @can('signal.rule.manage')
        <form method="POST" action="{{ route('tech.admin.system.signals.rules.update', $rule) }}" class="d-grid gap-3 mb-3">
            @csrf
            @method('PUT')
            @include('signal::Tech.rules.partials.fields', ['rule' => $rule])
        </form>
    @endcan

    <div class="card">
        <div class="card-header">
            <span class="fw-semibold">Recent Executions</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Signal</th>
                        <th>Status</th>
                        <th>Executed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rule->executions->sortByDesc('executed_at')->take(25) as $execution)
                        <tr>
                            <td><a href="{{ route('tech.admin.system.signals.show', $execution->signal) }}">{{ $execution->signal?->signal_type }}</a></td>
                            <td>{{ ucfirst($execution->status) }}</td>
                            <td>{{ $execution->executed_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-4">This rule has not executed.</td>
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

@section('rightbar')
    @include('signal::Tech.rules.partials.reference')
@endsection
