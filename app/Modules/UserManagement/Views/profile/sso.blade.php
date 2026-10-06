@extends('layouts.default_tech')
@section('title', 'Work Account')
@section('pageHeader')<h1 class="h5 mb-0">Work Account</h1>@endsection
@section('sidebar')@include('usermanagement::profile.partials.sidebar')@endsection
@section('content')
{{-- Work identity and explicit account linking --}}
<div class="card"><div class="card-body">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <p>Your work account signs you in to your existing Nexum account. Your Nexum permissions and two-factor requirements still apply.</p>
    @if($identity)<p>Linked provider: <strong>{{ $identity->issuer }}</strong></p>@endif
    @if($available || $identity)
        <form method="POST" action="{{ route($identity ? 'tech.profile.sso.unlink' : 'tech.profile.sso.link') }}">
            @csrf
            <div class="mb-3">
                <label for="sso-password" class="form-label">Current Nexum password</label>
                <input id="sso-password" class="form-control" type="password" name="current_password" autocomplete="current-password" required>
            </div>
            @if(auth()->user()->hasConfirmedTwoFactor())
            <div class="mb-3">
                <label for="sso-code" class="form-label">Nexum authenticator code</label>
                <input id="sso-code" class="form-control" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            </div>
            @endif
            <button class="btn {{ $identity ? 'btn-outline-danger' : 'btn-primary' }}" type="submit">{{ $identity ? 'Unlink work account' : 'Link work account' }}</button>
        </form>
    @else
        <p class="text-body-secondary mb-0">Work account sign-in is not enabled. Continue using your Nexum password.</p>
    @endif
</div></div>
{{-- Explicit provider logout, distinct from ordinary Nexum logout --}}
@if(session('sso.grant'))
<div class="card mt-3"><div class="card-body">
    <p>Signing out of your work account may also sign you out of other connected work applications.</p>
    <form method="POST" action="{{ route('tech.profile.sso.logout') }}">@csrf
        <button class="btn btn-outline-secondary" type="submit">Sign out of work account</button>
    </form>
</div></div>
@endif
@endsection
