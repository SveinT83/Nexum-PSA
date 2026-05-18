@extends('layouts.default_tech')

@section('title', 'Security Settings')

@section('pageHeader')
    <h1>Security Settings</h1>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">

        {{-- Flash messages --}}
        @if(session('status') === 'two-factor-enabled')
            <div class="alert alert-info">
                Two-factor authentication has been enabled. Scan the QR code below and confirm with a code from your authenticator app.
            </div>
        @elseif(session('status') === 'two-factor-confirmed')
            <div class="alert alert-success">
                ✅ Two-factor authentication confirmed and active. Your account is now protected.
            </div>
        @elseif(session('status') === 'two-factor-disabled')
            <div class="alert alert-warning">
                Two-factor authentication has been disabled. Your account is less secure.
            </div>
        @elseif(session('status') === 'recovery-codes-regenerated')
            <div class="alert alert-info">
                New recovery codes have been generated. Save them in a safe place — the old codes are no longer valid.
            </div>
        @endif

        {{-- Two-Factor Authentication Card --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <h4 class="mb-0">
                    <i class="bi bi-shield-lock me-2"></i>Two-Factor Authentication
                </h4>
            </div>
            <div class="card-body">
                @if($twoFactorEnabled && $twoFactorConfirmed)
                    {{-- 2FA is active --}}
                    <div class="alert alert-success d-flex align-items-center" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <div>
                            <strong>Two-factor authentication is active.</strong><br>
                            You will be asked for a verification code each time you sign in.
                        </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        {{-- Regenerate recovery codes --}}
                        <form action="{{ route('tech.profile.security.recovery-codes') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-outline-warning"
                                    onclick="return confirm('This will invalidate your existing recovery codes. Continue?')">
                                🔄 Regenerate Recovery Codes
                            </button>
                        </form>

                        {{-- Disable 2FA --}}
                        <form action="{{ route('tech.profile.security.2fa.disable') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger"
                                    onclick="return confirm('Are you sure you want to disable two-factor authentication? This reduces your account security.')">
                                🚫 Disable Two-Factor
                            </button>
                        </form>
                    </div>

                    {{-- Show recovery codes section (toggle) --}}
                    <div class="mt-3">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#recoveryCodes">
                            👁 Show Recovery Codes
                        </button>
                        <div class="collapse mt-2" id="recoveryCodes">
                            <div class="alert alert-secondary">
                                <p class="mb-2"><strong>Store these recovery codes in a safe place.</strong> Each code can only be used once.</p>
                                @php
                                    $codes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?? [];
                                @endphp
                                <div class="row">
                                    @foreach($codes as $code)
                                        <div class="col-md-3 col-6 mb-1">
                                            <code class="fs-6">{{ $code }}</code>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                @elseif($twoFactorEnabled && !$twoFactorConfirmed)
                    {{-- 2FA enabled but not yet confirmed — show QR code --}}
                    <div class="alert alert-warning d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div>
                            <strong>Two-factor authentication is enabled but not yet confirmed.</strong><br>
                            Scan the QR code below with your authenticator app, then enter the code to confirm.
                        </div>
                    </div>

                    {{-- QR Code --}}
                    <div class="text-center mb-3">
                        @php
                            $qrCodeUrl = $user->twoFactorQrCodeSvg();
                        @endphp
                        <div class="d-inline-block p-3 bg-white border rounded">
                            {!! $qrCodeUrl !!}
                        </div>
                    </div>

                    {{-- Confirm form --}}
                    <form action="{{ route('tech.profile.security.2fa.confirm') }}" method="POST">
                        @csrf
                        <div class="row justify-content-center mb-3">
                            <div class="col-md-6">
                                <label for="code" class="form-label">Verification Code</label>
                                <input type="text" name="code" id="code"
                                       class="form-control form-control-lg text-center @error('code') is-invalid @enderror"
                                       autocomplete="one-time-code"
                                       inputmode="numeric"
                                       pattern="[0-9]*"
                                       autofocus
                                       required
                                       placeholder="000000">
                                @error('code')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary btn-lg">Confirm & Activate</button>
                        </div>
                    </form>

                    {{-- Cancel / disable --}}
                    <div class="text-center mt-3">
                        <form action="{{ route('tech.profile.security.2fa.disable') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-link text-muted">Cancel setup</button>
                        </form>
                    </div>

                @else
                    {{-- 2FA is not enabled --}}
                    <div class="alert alert-secondary d-flex align-items-center" role="alert">
                        <i class="bi bi-shield me-2"></i>
                        <div>
                            <strong>Two-factor authentication is not enabled.</strong><br>
                            Adding a second verification step makes your account significantly more secure.
                        </div>
                    </div>

                    <form action="{{ route('tech.profile.security.2fa.enable') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-success btn-lg">
                            🛡 Enable Two-Factor Authentication
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Password change card --}}
        <div class="card shadow-sm">
            <div class="card-header">
                <h4 class="mb-0">
                    <i class="bi bi-key me-2"></i>Change Password
                </h4>
            </div>
            <div class="card-body">
                <form action="{{ route('tech.profile.security.password') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" name="current_password" id="current_password"
                               class="form-control @error('current_password') is-invalid @enderror"
                               required autocomplete="current-password">
                        @error('current_password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">New Password</label>
                        <input type="password" name="password" id="password"
                               class="form-control @error('password') is-invalid @enderror"
                               required autocomplete="new-password" minlength="8">
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="password_confirmation" class="form-label">Confirm New Password</label>
                        <input type="password" name="password_confirmation" id="password_confirmation"
                               class="form-control" required autocomplete="new-password" minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>
        </div>

    </div>
</div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
@endsection

@section('rightbar')
    <h3>Notifications</h3>
    <ul>
        <li>No new notifications.</li>
    </ul>
@endsection