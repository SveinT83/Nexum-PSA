@extends('layouts.default_tech')

@section('title', 'Ticket Assignment Rules')

<!-- Page header: assignment rules run after Ticket Rules and before profile scoring fallback. -->
@section('pageHeader')
    <h1>Ticket Assignment Rules</h1>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <!-- Rule creation: MVP supports one or more exact-match conditions and one assign-user action. -->
    <x-card.default title="Create assignment rule">
        <form method="POST" action="{{ route('tech.admin.settings.tickets.assignment-rules.store') }}">
            @csrf
            <input type="hidden" name="is_active" value="0">
            <input type="hidden" name="stop_processing" value="0">

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Name</label>
                    <input id="name" name="name" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label for="weight" class="form-label">Weight</label>
                    <input id="weight" name="weight" type="number" min="0" max="100000" class="form-control" value="10" required>
                </div>
                <div class="col-md-3">
                    <label for="action_value" class="form-label">Assign to</label>
                    <select id="action_value" name="action_value" class="form-select" required>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="stop_processing" name="stop_processing" value="1" checked>
                        <label class="form-check-label" for="stop_processing">Stop after assignment</label>
                    </div>
                </div>
            </div>

            <hr>
            <h2 class="h6">Conditions</h2>
            @foreach([0, 1, 2] as $index)
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-md-4">
                        <label class="form-label" for="condition_field_{{ $index }}">Field</label>
                        <select id="condition_field_{{ $index }}" name="conditions[{{ $index }}][field]" class="form-select">
                            @foreach(['client_id' => 'Client', 'contact_id' => 'Contact', 'queue_id' => 'Queue', 'category_id' => 'Category', 'tag_ids' => 'Tag', 'priority_id' => 'Priority', 'ticket_type_id' => 'Ticket type', 'channel' => 'Channel'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="condition_operator_{{ $index }}">Operator</label>
                        <select id="condition_operator_{{ $index }}" name="conditions[{{ $index }}][operator]" class="form-select">
                            <option value="equals">Equals</option>
                            <option value="not_equals">Not equals</option>
                            <option value="contains">Contains</option>
                            <option value="present">Present</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="condition_value_{{ $index }}">Value</label>
                        <input id="condition_value_{{ $index }}" name="conditions[{{ $index }}][value]" class="form-control" placeholder="Record id or channel value" list="assignment-rule-condition-values">
                    </div>
                </div>
            @endforeach

            <datalist id="assignment-rule-condition-values">
                @foreach($clients as $client)
                    <option value="{{ $client->id }}">client: {{ $client->name }}</option>
                @endforeach
                @foreach($contacts as $contact)
                    <option value="{{ $contact->id }}">contact: {{ $contact->name }}</option>
                @endforeach
                @foreach($queues as $queue)
                    <option value="{{ $queue->id }}">queue: {{ $queue->name }}</option>
                @endforeach
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">category: {{ $category->name }}</option>
                @endforeach
                @foreach($tags as $tag)
                    <option value="{{ $tag->id }}">tag: {{ $tag->name }}</option>
                @endforeach
                @foreach($priorities as $priority)
                    <option value="{{ $priority->id }}">priority: {{ $priority->name }}</option>
                @endforeach
                @foreach($types as $type)
                    <option value="{{ $type->id }}">ticket type: {{ $type->name }}</option>
                @endforeach
            </datalist>

            <div class="text-end">
                <button type="submit" class="btn btn-primary">Create assignment rule</button>
            </div>
        </form>
    </x-card.default>

    <!-- Rule list: hit counts show whether a rule is actually driving assignments. -->
    <x-card.default title="Rules">
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Conditions</th>
                        <th>Assign to</th>
                        <th>Hits</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rules as $rule)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $rule->name }}</div>
                                <div class="small text-muted">{{ $rule->is_active ? 'Active' : 'Disabled' }} · Weight {{ $rule->weight }}</div>
                            </td>
                            <td class="small">
                                @foreach((array) $rule->conditions_json as $condition)
                                    <div>{{ $condition['field'] ?? '' }} {{ $condition['operator'] ?? '' }} {{ $condition['value'] ?? '' }}</div>
                                @endforeach
                            </td>
                            <td>{{ $users->firstWhere('id', (int) $rule->action_value)?->name ?? $rule->action_value }}</td>
                            <td>{{ $rule->hit_count }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('tech.admin.settings.tickets.assignment-rules.destroy', $rule) }}" onsubmit="return confirm('Delete this assignment rule?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No assignment rules yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card.default>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection
