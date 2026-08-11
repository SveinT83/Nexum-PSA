@extends('layouts.default_tech')

@section('title', 'New booking service')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">New booking service</h1>
    </div>
    <div class="col-auto">
        <x-buttons.back :url="route('tech.admin.system.booking.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Booking Service Create Form -->
    <!-- ------------------------------------------------- -->
    @include('booking::Admin.settings._form', ['setting' => $setting, 'services' => $services, 'users' => $users])
@endsection

@section('sidebar')
    <x-nav.admin-menu group="booking" />
@endsection

@section('rightbar')
@endsection
