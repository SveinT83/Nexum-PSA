@extends('customerportal::layouts.portal')

@section('title', 'Accept portal invitation')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Invitation Acceptance -->
    <!-- ------------------------------------------------- -->
    <div class="row justify-content-center">
        <div class="col-lg-7 col-xl-6">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h1 class="h5 mb-0">Accept portal invitation</h1>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="small text-muted">Customer</div>
                        <div class="fw-semibold">{{ $invitation->client?->name }}</div>
                        <div class="small text-muted">{{ $invitation->site?->name ?: 'All sites' }}</div>
                    </div>

                    <div class="mb-3">
                        <div class="small text-muted">Contact</div>
                        <div class="fw-semibold">{{ $invitation->contact?->display_name }}</div>
                        <div class="small text-muted">{{ $invitation->email }}</div>
                    </div>

                    <form method="POST" action="{{ route('customer-portal.invitations.accept.store', ['token' => $token]) }}">
                        @csrf

                        @if($passwordRequired)
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" id="password" name="password" class="form-control @error('password') is-invalid @enderror" required autocomplete="new-password">
                                @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="mb-3">
                                <label for="password_confirmation" class="form-label">Confirm password</label>
                                <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" required autocomplete="new-password">
                            </div>
                        @else
                            <div class="alert alert-info small" role="alert">
                                This email already has an active Nexum account. Accepting the invitation activates portal access for the selected customer scope.
                            </div>
                        @endif

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                                Activate portal access
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
