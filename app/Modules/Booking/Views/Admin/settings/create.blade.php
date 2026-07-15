@extends('layouts.default_tech')

@section('title', 'New booking service')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">New booking service</h1>
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
