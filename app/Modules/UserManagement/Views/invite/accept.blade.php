@extends('layouts.guest')

@section('title', 'Accept Invitation')

@section('content')
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h3 class="card-title text-center mb-4">Accept Your Invitation</h3>

                    <p class="text-center text-muted mb-3">
                        Welcome, <strong>{{ $user->name }}</strong>!<br>
                        Choose a password to activate your account.
                    </p>

                    <form action="{{ route('invite.accept.post', ['token' => $token]) }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" name="password" id="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   required autocomplete="new-password" minlength="8">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Confirm Password</label>
                            <input type="password" name="password_confirmation" id="password_confirmation"
                                   class="form-control"
                                   required autocomplete="new-password" minlength="8">
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Activate Account</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection