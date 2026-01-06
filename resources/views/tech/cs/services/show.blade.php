@extends('layouts.default_tech')

<!-- ------------------------------------------------- -->
<!-- Page Header -->
<!-- ------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">

        <h2 class="h4 mb-0">Show service</h2>
        <div>

            <!-- Edit button -->
            <a href="{{ route('tech.services.edit', $service) }}" class="btn btn-sm btn-primary">Edit</a>

            <!-- Back button -->
            <a href="{{ route('tech.services.index') }}" class="btn btn-sm btn-primary">Back</a>
        </div>
    </div>
@endsection

<!-- ------------------------------------------------- -->
<!-- Content -->
<!-- ------------------------------------------------- -->
@section('content')

    <!-- ------------------------------------------------- -->
    <!-- Form -->
    <!-- ------------------------------------------------- -->
    @include('partials.forms.create-service-form', [
        'method' => 'post',
        'service' => $service,
        'enabled' => 'disabled',
        'title' => 'New service',
        'formRoute' => "edit",
        'buttonText' => "Edit"
    ])

@endsection

<!-- ------------------------------------------------- -->
<!-- Sidebar - Left -->
<!-- ------------------------------------------------- -->
@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

<!-- ------------------------------------------------- -->
<!-- Sidebar - Right -->
<!-- ------------------------------------------------- -->
@section('rightbar')
    <div class="p-3 small text-muted">Recent services (MVP later)</div>
@endsection
