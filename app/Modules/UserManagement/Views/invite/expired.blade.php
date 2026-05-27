@extends('layouts.guest')

@section('title', 'Invitation Expired')

@section('content')
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body p-4 text-center">
                    <h3 class="card-title mb-3">Invitation Expired or Invalid</h3>

                    <p class="text-muted">
                        This invitation link has expired or is no longer valid.<br>
                        Please contact your administrator to request a new invitation.
                    </p>

                    <a href="{{ route('login') }}" class="btn btn-outline-primary mt-3">
                        Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection