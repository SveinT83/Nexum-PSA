@extends('layouts.default_tech')

@section('title', 'Ticket Rules')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between">
        <div>
            <h1>Ticket Rules</h1>
            <p class="text-muted mb-0">Rules that classify and route records after they become tickets.</p>
        </div>
        <a href="{{ route('tech.admin.settings.tickets.rules.create') }}" class="btn btn-primary">Add rule</a>
    </div>
@endsection

@section('content')
    <div class="col-12">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if($rules->isEmpty())
            <div class="text-center py-5">
                <h2 class="h6 text-muted mb-3">No ticket rules yet</h2>
                <a href="{{ route('tech.admin.settings.tickets.rules.create') }}" class="btn btn-outline-primary">Add first rule</a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 90px;">Weight</th>
                            <th>Rule</th>
                            <th>Conditions</th>
                            <th>Actions</th>
                            <th class="text-center" style="width: 110px;">Flow</th>
                            <th class="text-center" style="width: 110px;">Status</th>
                            <th class="text-end" style="width: 220px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rules as $rule)
                            <tr>
                                <td>{{ $rule->weight }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $rule->name }}</div>
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
                                <td class="text-end">
                                    <a href="{{ route('tech.admin.settings.tickets.rules.edit', $rule) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                                    <form action="{{ route('tech.admin.settings.tickets.rules.toggle', $rule) }}" method="POST" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm {{ $rule->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                                            {{ $rule->is_active ? 'Disable' : 'Enable' }}
                                        </button>
                                    </form>
                                    <form action="{{ route('tech.admin.settings.tickets.rules.destroy', $rule) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this ticket rule?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <h3>Ticket Settings</h3>
    <ul>
        <li><a href="{{ route('tech.admin.settings.tickets') }}">Tickets</a></li>
        <li><a href="{{ route('tech.admin.settings.tickets.rules') }}">Rules</a></li>
        <li><a href="{{ route('tech.admin.settings.tickets.workflows') }}">Workflows</a></li>
    </ul>
@endsection

@section('rightbar')
    <x-card.default title="Execution">
        <p class="small text-muted mb-0">Email Rules decide whether a message becomes a ticket. Ticket Rules then set type, queue, priority, and later workflow/SLA details.</p>
    </x-card.default>
@endsection
