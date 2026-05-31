@extends('layouts.default_tech')

@section('title', 'Profile View Settings')

@section('sidebar')
    @include('usermanagement::profile.partials.sidebar')
@endsection

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-1">View Settings</h1>
        <div class="text-muted small">Personal display preferences for the technician workspace.</div>
    </div>
@endsection

@section('content')
    <!-- Personal view status -->
    <div class="card">
        <div class="card-body">
            <h2 class="h6">View preferences are not configured yet</h2>
            <p class="text-muted mb-0">
                Personal display options, including light and dark mode, will be added after company branding is in place.
            </p>
        </div>
    </div>
@endsection
