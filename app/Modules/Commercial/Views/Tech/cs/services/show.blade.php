@extends('layouts.default_tech')

<!-- ------------------------------------------------- -->
<!-- Page Header -->
<!-- ------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">

        <h1>{{ $service->name }}</h1>
        <div>

            @unless($service->isIntegrationManaged())
                <!-- Edit button -->
                <x-buttons.editlink url="{{ route('tech.services.edit', $service) }}" class="mb-0">Edit</x-buttons.editlink>
            @endunless

            <!-- Back button -->
            <x-buttons.back url="{{ route('tech.services.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

<!-- ------------------------------------------------- -->
<!-- Content -->
<!-- ------------------------------------------------- -->
@section('content')

    @if($service->isIntegrationManaged())
        <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
            <span>
                This Service is owned and updated by its active source Integration. Manual changes and deletion are locked.
            </span>
            <x-integration.source-ownership :record="$service" />
        </div>
    @elseif($service->managed_externally && $service->source !== 'nexum')
        <div class="alert alert-secondary py-2">
            This Service was imported from {{ $service->sourceIntegration?->name ?? 'Cloud Factory' }}.
            The source Integration is not active, so the retained Nexum record is editable.
        </div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Form -->
    <!-- ------------------------------------------------- -->
    @include('commercial::Tech.partials.forms.create-service-form', [
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
    <x-nav.sales-menu />
@endsection

<!-- ------------------------------------------------- -->
<!-- Sidebar - Right -->
<!-- ------------------------------------------------- -->
@section('rightbar')
@endsection
