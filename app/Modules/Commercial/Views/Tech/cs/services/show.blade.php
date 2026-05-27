@extends('layouts.default_tech')

<!-- ------------------------------------------------- -->
<!-- Page Header -->
<!-- ------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">

        <h1>{{ $service->name }}</h1>
        <div>

            <!-- Edit button -->
            <x-buttons.editlink url="{{ route('tech.services.edit', $service) }}" class="mb-0">Edit</x-buttons.editlink>

            <!-- Back button -->
            <x-buttons.back url="{{ route('tech.services.index') }}" class="mb-0">Back</x-buttons.back>
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
