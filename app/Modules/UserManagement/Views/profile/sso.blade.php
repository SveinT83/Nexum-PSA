@extends('layouts.default_tech')
@section('title', 'Work Account (SSO)')
@section('pageHeader')<h1 class="h5 mb-0">Work Account (SSO)</h1>@endsection
@section('sidebar')@include('usermanagement::profile.partials.sidebar')@endsection
@section('content')
{{-- Work identity and explicit account linking --}}
<div class="card"><div class="card-body">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    {{-- Keep failed local verification on this form without retaining secrets. --}}
    @error('sso_reauthentication')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror
    <p>Single sign-on (SSO) lets you use your organisation's work account to sign in to your existing Nexum account. Link it once here before using work account sign-in.</p>
    <p>Your organisation manages MFA for work account sign-in. Nexum does not ask for an extra local 2FA code when you sign in with SSO. Your Nexum permissions stay the same.</p>
    <p>Keep your Nexum authenticator and recovery codes: local 2FA still protects password sign-in and is required for sensitive account changes when enabled.</p>
    {{-- Show setup steps only while an account still needs to be linked. --}}
    @if($identity)
        <p>Linked provider: <strong>{{ $identity->issuer }}</strong></p>
        <p>Your work account is linked. Next time you sign in, choose <strong>Sign in with work account</strong> on the Nexum login page and complete any authentication requested by your organisation.</p>
        <p class="text-body-secondary">To disconnect SSO, confirm your Nexum credentials below and choose <strong>Unlink work account</strong>. This ends your SSO sessions. You can still sign in with your Nexum password.</p>
    @elseif($available)
        <h2 class="h6">How to connect your work account</h2>
        <ol>
            <li>Enter your current <strong>Nexum password</strong> below.</li>
            <li>If shown, enter the current code for <strong>Nexum</strong> from your authenticator app.</li>
            <li>Choose <strong>Link work account</strong>. You will be taken to your organisation's sign-in service. Sign in with the work account you want to connect and complete any requested MFA.</li>
            <li>You will return to this page. Check that <strong>Work account linked.</strong> and the linked provider are displayed.</li>
        </ol>
        <p>After linking, choose <strong>Sign in with work account</strong> on the Nexum login page. Your Nexum password remains available for local sign-in.</p>
    @endif
    @if($available || $identity)
        <form method="POST" action="{{ route($identity ? 'tech.profile.sso.unlink' : 'tech.profile.sso.link') }}">
            @csrf
            <div class="mb-3">
                <label for="sso-password" class="form-label">Current Nexum password</label>
                <input id="sso-password" class="form-control" type="password" name="current_password" autocomplete="current-password" required>
            </div>
            @if(auth()->user()->hasConfirmedTwoFactor())
            <div class="mb-3">
                <label for="sso-code" class="form-label">2FA code for Nexum</label>
                <input id="sso-code" class="form-control" name="code" inputmode="numeric" autocomplete="one-time-code" aria-describedby="sso-code-help" required>
                <div id="sso-code-help" class="form-text">Enter the current two-factor code for Nexum from your authenticator app.</div>
            </div>
            @endif
            <button class="btn {{ $identity ? 'btn-outline-danger' : 'btn-primary' }}" type="submit">{{ $identity ? 'Unlink work account' : 'Link work account' }}</button>
        </form>
    @else
        <p class="text-body-secondary mb-0">Work account sign-in is not enabled. Ask your Nexum administrator to enable SSO before linking your account. Continue using your Nexum password in the meantime.</p>
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
