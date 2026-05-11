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
