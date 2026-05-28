@extends('layouts.guest')

@section('title', 'Two-Factor Authentication')

@section('content')
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h3 class="card-title text-center mb-3">Two-Factor Authentication</h3>

                    <p class="text-center text-muted mb-4">
                        Please enter the authentication code from your authenticator app to continue.
                    </p>

                    @if ($errors->any())
                        <div class="alert alert-danger">
                            @foreach ($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ route('two-factor.login') }}">
                        @csrf

                        <div class="mb-3">
                            <label for="code" class="form-label">Authentication Code</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
                                <input type="text" name="code" id="code"
                                       class="form-control form-control-lg text-center @error('code') is-invalid @enderror"
                                       autocomplete="one-time-code"
                                       inputmode="numeric"
                                       pattern="[0-9]*"
                                       autofocus
                                       required>
                            </div>
                            @error('code')
                                <div class="invalid-feedback d-block mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary btn-lg">Verify</button>
                        </div>
                    </form>

                    <div class="text-center">
                        <button type="button" class="btn btn-link btn-sm text-muted" data-bs-toggle="collapse" data-bs-target="#recovery-code-section">
                            Use a recovery code instead
                        </button>
                    </div>

                    <div class="collapse mt-3" id="recovery-code-section">
                        <form method="POST" action="{{ route('two-factor.login') }}">
                            @csrf
                            <div class="mb-3">
                                <label for="recovery_code" class="form-label">Recovery Code</label>
                                <input type="text" name="recovery_code" id="recovery_code"
                                       class="form-control text-center @error('recovery_code') is-invalid @enderror"
                                       autocomplete="off"
                                       required>
                                @error('recovery_code')
                                    <div class="invalid-feedback d-block mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-outline-secondary">Use Recovery Code</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
