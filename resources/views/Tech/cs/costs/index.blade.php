@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Costs</h2>
        <div>
            <a href="{{ route('tech.costs.create') }}" class="btn btn-sm btn-primary">New Cost</a>
        </div>
    </div>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- Alert message -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- If Cost -->
    <!-- ------------------------------------------------- -->
    @if($costs->count())

        <!-- ------------------------------------------------- -->
        <!-- An header before showing costs -->
        <!-- ------------------------------------------------- -->
        <div class="mb-3 row justify-content-start">
            <b class="col-4 d-none d-sm-block">Name:</b>
            <b class="col-2 d-none d-sm-block">Cost:</b>
            <b class="col-2 d-none d-sm-block">Unit:</b>
            <b class="col-2 d-none d-sm-block">Recurrence:</b>
            <b class="col-2 d-none d-sm-block">User:</b>
        </div>

        @foreach($costs as $cost)
            <div class="row border-bottom mb-3 justify-content-start">
                <div class="col-md-4">
                    <p><b class="d-sm-none">Name: </b>{{ $cost -> name }}</p>
                </div>
                <div class="col-md-2">
                    <p><b class="d-sm-none">Cost: </b>{{ $cost -> cost }}</p>
                </div>
                <div class="col-md-2">
                    <p><b class="d-sm-none">Unit: </b>{{ $cost -> unit }}</p>
                </div>
                <div class="col-md-2">
                    <p><b class="d-sm-none">Recurrence: </b>{{ $cost -> recurrence }}</p>
                </div>
                <div class="col-md-2 fs-6 fw-lighter">
                    <p><b class="d-sm-none">Created by: </b> {{ $cost->creator?->name }}</p>
                    <p><b class="d-sm-none">Updated by: </b> {{ $cost->updater?->name }}</p>
                </div>
            </div>
        @endforeach
    @endif

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent services (MVP later)</div>
@endsection
