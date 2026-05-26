@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Edit service</h1>
        <div>
            <x-buttons.back url="{{ route('tech.services.show', $service) }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- Form -->
    <!-- ------------------------------------------------- -->
    @include('commercial::Tech.partials.forms.create-service-form', [
        'service' => $service,
        'method' => 'post',
        'enabled' => 'enabled',
        'title' => 'Update',
        'formRoute' => "update",
        'buttonText' => "Update"
    ])

@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
@endsection
