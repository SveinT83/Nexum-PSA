@extends('layouts.default_tech')

@section('title', 'Ticket Rules')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between">
        <h1>Ticket Rules</h1>
    </div>
@endsection

@section('content')
    <div class="col-12">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-3">
                <h2 class="h6 mb-0">Rules</h2>
                <x-buttons.addlink url="{{ route('tech.admin.settings.tickets.rules.create') }}">New rule</x-buttons.addlink>
            </div>
            <div class="card-body p-0">
                @if($rules->isEmpty())
                    <div class="text-center py-5 px-3">
                        <h3 class="h6 text-muted mb-3">No ticket rules yet</h3>
                        <x-buttons.addlink url="{{ route('tech.admin.settings.tickets.rules.create') }}">Add first rule</x-buttons.addlink>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 90px;">Weight</th>
                                    <th>Rule</th>
                                    <th>Conditions</th>
                                    <th>Actions</th>
                                    <th class="text-center" style="width: 110px;">Flow</th>
                                    <th class="text-center" style="width: 110px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rules as $rule)
                                    <tr>
                                        <td>{{ $rule->weight }}</td>
                                        <td>
                                            <a href="{{ route('tech.admin.settings.tickets.rules.edit', $rule) }}" class="fw-semibold text-decoration-none">
                                                {{ $rule->name }}
                                            </a>
                                            <div class="small text-muted">{{ $rule->trigger }}</div>
                                            @if($rule->description)
                                                <div class="small text-muted">{{ $rule->description }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @foreach((array) $rule->conditions_json as $condition)
                                                <div class="small">
                                                    <code>{{ $condition['field'] ?? '' }}</code>
                                                    {{ str_replace('_', ' ', $condition['operator'] ?? '') }}
                                                    @if(($condition['value'] ?? '') !== '')
                                                        <code>{{ $condition['value'] }}</code>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </td>
                                        <td>
                                            @foreach((array) $rule->actions_json as $action)
                                                <div class="small">
                                                    <code>{{ str_replace('_', ' ', $action['type'] ?? '') }}</code>
                                                    @if(($action['value'] ?? '') !== '')
                                                        <code>{{ $action['value'] }}</code>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </td>
                                        <td class="text-center">
                                            <span class="badge {{ $rule->stop_processing ? 'text-bg-warning' : 'text-bg-light' }}">{{ $rule->stop_processing ? 'Stop' : 'Continue' }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge {{ $rule->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $rule->is_active ? 'Active' : 'Disabled' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('rightbar')
    <x-card.default title="Execution">
        <p class="small text-muted mb-0">Email Rules decide whether a message becomes a ticket. Ticket Rules then set type, queue, priority, and later workflow/SLA details.</p>
    </x-card.default>
@endsection
