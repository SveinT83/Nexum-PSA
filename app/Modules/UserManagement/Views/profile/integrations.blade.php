@extends('layouts.default_tech')

@section('title', 'Profile Integrations')

@section('sidebar')
    @include('usermanagement::profile.partials.sidebar')
@endsection

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-1">Profile Integrations</h1>
        <div class="text-muted small">Personal integration settings that belong to the signed-in technician.</div>
    </div>
@endsection

@section('content')
    <!-- Personal integration status -->
    <div class="card">
        <div class="card-body">
            <h2 class="h6">No personal integrations are configured</h2>
            <p class="text-muted mb-0">
                Personal integration tokens and provider-specific technician URLs will be managed here when those integrations are implemented.
            </p>
        </div>
    </div>
@endsection
