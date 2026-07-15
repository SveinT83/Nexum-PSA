@extends('customerportal::layouts.portal')

@section('title', 'Portal invitation expired')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Expired Portal Invitation -->
    <!-- ------------------------------------------------- -->
    <div class="row justify-content-center">
        <div class="col-lg-7 col-xl-6">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h1 class="h5 mb-0">Portal invitation expired</h1>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted">This invitation link is invalid, expired, already accepted, or revoked. Contact your service provider for a new portal invitation.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
