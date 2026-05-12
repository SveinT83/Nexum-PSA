@extends('layouts.default_tech')

@section('title', 'Ticket Settings')

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Ticket-specific administration settings owned by the Ticket module. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <h1>Ticket Settings</h1>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Email settings -->
    <!-- Reuses EmailAccount.defaults_for as the source of truth for ticket outbound sender defaults. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Email">
        <form method="POST" action="{{ route('tech.admin.settings.tickets.default-email-account.update') }}">
            @csrf

            <div class="mb-3">
                <label for="email_account_id" class="form-label">Default outbound account</label>
                <select id="email_account_id" name="email_account_id" class="form-select @error('email_account_id') is-invalid @enderror">
                    <option value="">No default ticket account</option>
                    @foreach ($emailAccounts as $account)
                        <option value="{{ $account->id }}" @selected(old('email_account_id', $defaultTicketEmailAccount?->id) == $account->id)>
                            {{ $account->address }}@if ($account->description) - {{ $account->description }}@endif
                        </option>
                    @endforeach
                </select>
                @error('email_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">
                    This updates the same account default used by Email Settings for the <code>tickets</code> scope.
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save email settings</button>
        </form>
    </x-card.default>

    <x-card.default title="Ticket Types">
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Slug</th>
                        <th class="text-center">Tickets</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($types as $type)
                        <tr>
                            <td>
                                <form id="type-form-{{ $type->id }}" method="POST" action="{{ route('tech.admin.settings.tickets.types.update', $type) }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="hidden" name="is_deletable" value="0">
                                    <input type="text" name="name" class="form-control form-control-sm mb-1" value="{{ $type->name }}" required>
                                    <textarea name="description" class="form-control form-control-sm" rows="1" placeholder="Description">{{ $type->description }}</textarea>
                                </form>
                            </td>
                            <td>
                                <input form="type-form-{{ $type->id }}" type="text" name="slug" class="form-control form-control-sm" value="{{ $type->slug }}">
                                <input form="type-form-{{ $type->id }}" type="number" name="sort_order" class="form-control form-control-sm mt-1" value="{{ $type->sort_order }}" min="0">
                            </td>
                            <td class="text-center">{{ $type->tickets_count }}</td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input form="type-form-{{ $type->id }}" class="form-check-input" type="checkbox" name="is_active" value="1" @checked($type->is_active)>
                                </div>
                                <div class="small text-muted">
                                    {{ $type->is_system ? 'System' : 'Custom' }}
                                </div>
                            </td>
                            <td class="text-end">
                                <button form="type-form-{{ $type->id }}" type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                <form method="POST" action="{{ route('tech.admin.settings.tickets.types.destroy', $type) }}" class="d-inline" onsubmit="return confirm('Delete this ticket type?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" @disabled(! $type->is_deletable || $type->tickets_count > 0)>Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('tech.admin.settings.tickets.types.store') }}" class="row g-2 align-items-end">
            @csrf
            <input type="hidden" name="is_active" value="1">
            <input type="hidden" name="is_deletable" value="1">
            <div class="col-md-3">
                <label class="form-label" for="type_name">New type</label>
                <input id="type_name" name="name" class="form-control" placeholder="Lead" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="type_slug">Slug</label>
                <input id="type_slug" name="slug" class="form-control" placeholder="lead">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="type_description">Description</label>
                <input id="type_description" name="description" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="type_sort_order">Sort</label>
                <input id="type_sort_order" name="sort_order" type="number" min="0" class="form-control" value="100">
            </div>
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-outline-primary">Add type</button>
            </div>
        </form>
    </x-card.default>

    <x-card.default title="Queues">
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th class="text-center">Tickets</th>
                        <th class="text-center">Flags</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($queues as $queue)
                        <tr>
                            <td>
                                <form id="queue-form-{{ $queue->id }}" method="POST" action="{{ route('tech.admin.settings.tickets.queues.update', $queue) }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="hidden" name="is_default" value="0">
                                    <input type="text" name="name" class="form-control form-control-sm mb-1" value="{{ $queue->name }}" required>
                                    <input type="text" name="slug" class="form-control form-control-sm" value="{{ $queue->slug }}">
                                </form>
                            </td>
                            <td>
                                <input form="queue-form-{{ $queue->id }}" type="email" name="email_address" class="form-control form-control-sm mb-1" value="{{ $queue->email_address }}">
                                <textarea form="queue-form-{{ $queue->id }}" name="description" class="form-control form-control-sm" rows="1" placeholder="Description">{{ $queue->description }}</textarea>
                            </td>
                            <td class="text-center">{{ $queue->tickets_count }}</td>
                            <td class="text-center">
                                <label class="form-check small">
                                    <input form="queue-form-{{ $queue->id }}" class="form-check-input" type="checkbox" name="is_active" value="1" @checked($queue->is_active)> Active
                                </label>
                                <label class="form-check small">
                                    <input form="queue-form-{{ $queue->id }}" class="form-check-input" type="checkbox" name="is_default" value="1" @checked($queue->is_default)> Default
                                </label>
                                <input form="queue-form-{{ $queue->id }}" type="number" name="sort_order" class="form-control form-control-sm mt-1" value="{{ $queue->sort_order }}" min="0">
                            </td>
                            <td class="text-end">
                                <button form="queue-form-{{ $queue->id }}" type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                <form method="POST" action="{{ route('tech.admin.settings.tickets.queues.destroy', $queue) }}" class="d-inline" onsubmit="return confirm('Delete this queue?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" @disabled($queue->tickets_count > 0)>Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('tech.admin.settings.tickets.queues.store') }}" class="row g-2 align-items-end">
            @csrf
            <input type="hidden" name="is_active" value="1">
            <div class="col-md-3">
                <label class="form-label" for="queue_name">New queue</label>
                <input id="queue_name" name="name" class="form-control" placeholder="Support" required>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="queue_slug">Slug</label>
                <input id="queue_slug" name="slug" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="queue_email">Email address</label>
                <input id="queue_email" name="email_address" type="email" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="queue_sort_order">Sort</label>
                <input id="queue_sort_order" name="sort_order" type="number" min="0" class="form-control" value="100">
            </div>
            <div class="col-md-2">
                <label class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="is_default" value="1"> Default
                </label>
            </div>
            <div class="col-12">
                <label class="form-label" for="queue_description">Description</label>
                <input id="queue_description" name="description" class="form-control">
            </div>
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-outline-primary">Add queue</button>
            </div>
        </form>
    </x-card.default>
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
    <x-card.default title="Email note">
        <p class="small text-muted mb-0">
            The selected account will be used as the default sender when ticket replies are sent by email.
        </p>
    </x-card.default>
@endsection
