@extends('layouts.default_tech')
@section('title', 'Work Account Sign-in')
@section('pageHeader')<h1 class="h5 mb-0">Work Account Sign-in</h1>@endsection
@section('sidebar')<x-nav.admin-menu group="users" />@endsection
@section('content')
{{-- Provider setup and verified discovery status --}}
<div class="card"><div class="card-body">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <p>Configure Keycloak for internal employees. Customer Portal login is unchanged. Existing employees link their account from Profile &rarr; Work Account.</p>
    @unless(config('sso.enabled'))<div class="alert alert-info">Operational activation is off. Settings can be prepared, but work account sign-in remains unavailable.</div>@endunless
    <dl class="row">
        <dt class="col-sm-3">Callback URL</dt><dd class="col-sm-9 text-break">{{ \App\Modules\UserManagement\Sso\SsoProvider::endpoint('sso.callback') }}</dd>
        <dt class="col-sm-3">Back-channel logout URL</dt><dd class="col-sm-9 text-break">{{ \App\Modules\UserManagement\Sso\SsoProvider::endpoint('sso.backchannel') }}</dd>
        <dt class="col-sm-3">After logout</dt><dd class="col-sm-9 text-break">{{ \App\Modules\UserManagement\Sso\SsoProvider::endpoint('login') }}</dd>
    </dl>
    <form method="POST" action="{{ route('tech.admin.user_management.sso.update') }}">
        @csrf
        <div class="mb-3"><label for="issuer" class="form-label">Issuer URL</label>
            <input id="issuer" type="url" name="issuer" class="form-control" value="{{ $provider?->issuer }}" placeholder="https://auth.example.com/realms/company" required></div>
        <div class="mb-3"><label for="client-id" class="form-label">Client ID</label>
            <input id="client-id" name="client_id" class="form-control" value="{{ $provider?->client_id }}" required></div>
        <div class="mb-3"><label for="client-secret" class="form-label">Client secret</label>
            <input id="client-secret" type="password" name="client_secret" class="form-control" autocomplete="new-password" placeholder="{{ $provider ? 'Leave blank to keep the saved secret' : 'Enter client secret' }}"></div>
        <div class="form-check mb-3"><input id="sso-enabled" type="checkbox" name="enabled" value="1" class="form-check-input" @checked($provider?->enabled)>
            <label for="sso-enabled" class="form-check-label">Enable work account sign-in when operational activation is on</label></div>
        {{-- Sensitive changes require fresh local authentication --}}
        <div class="mb-3"><label for="current-password" class="form-label">Current Nexum password</label>
            <input id="current-password" type="password" name="current_password" class="form-control" autocomplete="current-password" required></div>
        @if(auth()->user()->hasConfirmedTwoFactor())
        <div class="mb-3"><label for="sso-code" class="form-label">Nexum authenticator code</label>
            <input id="sso-code" name="code" class="form-control" inputmode="numeric" autocomplete="one-time-code" required></div>
        @endif
        <p class="text-body-secondary">Saving verifies provider discovery and ends existing SSO sessions. Complete a real login test before relying on SSO. Local password login remains available.</p>
        <button type="submit" class="btn btn-primary">Save and verify provider</button>
    </form>
</div></div>
@endsection
