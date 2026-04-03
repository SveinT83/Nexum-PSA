@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h1 class="mb-0">{{$user->name ?? 'Unknown'}}</h1>
        <div>
            <x-buttons.back url="{{ route('tech.clients.users.index') }}">Back to users</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')

    <div class="row">

        <!-- ------------------------------------------------- -->
        <!-- Contact info -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-6">
            <div class="row mt-3">
                <div class="col-md-auto">
                    <x-buttons.tel tel="{{$user->phone}}"></x-buttons.tel>
                </div>
                <div class="col-md-auto">
                    <x-buttons.mailto email="{{$user->email}}"></x-buttons.mailto>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Contact info -->
        <!-- ------------------------------------------------- -->
        <div class="col-md-6">
            <p><b>Address</b> {{$user->address ?? '-'}}</p>
            <p><b>CO Address</b> {{$user->co_address ?? '-'}}</p>
            <p><b>CO Address</b> {{$user->co_address ?? '-'}}</p>
            <p>{{$user->zip ?? '-'}} {{$user->city ?? '-'}}</p>
<p>{{$user->county ?? '-'}} {{$user->country ?? '-'}}</p>
        </div>

    </div>
@endsection

@section('sidebar')
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent clients (MVP later)</div>
@endsection

