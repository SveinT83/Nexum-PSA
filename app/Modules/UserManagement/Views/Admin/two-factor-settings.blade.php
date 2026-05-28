@extends('layouts.default_tech')

@section('title', 'Two-Factor Authentication Settings')

@section('pageHeader')
    <h1>2FA Enforcement Settings</h1>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header">
                <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Two-Factor Authentication Enforcement</h4>
            </div>
            <div class="card-body">
                <form action="{{ route('tech.admin.user_management.2fa-settings.update') }}" method="POST">
                    @csrf

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input type="checkbox" name="enforce_two_factor" value="1"
                                   class="form-check-input" id="enforceSwitch"
                                   {{ $enforceTwoFactor ? 'checked' : '' }}>
                            <label class="form-check-label fw-bold" for="enforceSwitch">
                                Require two-factor authentication for selected roles
                            </label>
                        </div>
                        <p class="text-muted small mt-1">
                            When enabled, users with the selected roles will be required to set up 2FA
                            before they can access the platform. They will be redirected to the security
                            settings page until they confirm their 2FA setup.
                        </p>
                    </div>

                    <div class="mb-4" id="rolesSection">
                        <label class="form-label fw-bold">Roles that require 2FA</label>

                        @foreach($allRoles as $role)
                            <div class="form-check">
                                <input type="checkbox" name="enforce_two_factor_roles[]" value="{{ $role }}"
                                       class="form-check-input" id="role_{{ $role }}"
                                       {{ in_array($role, $enforcedRoles) ? 'checked' : '' }}>
                                <label class="form-check-label" for="role_{{ $role }}">
                                    {{ ucfirst($role) }}
                                </label>
                            </div>
                        @endforeach

                        @if(empty($allRoles))
                            <p class="text-muted">No roles found. Create roles in the Roles management section first.</p>
                        @endif
                    </div>

                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="users" />
@endsection

@section('rightbar')
@endsection
