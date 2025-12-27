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

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent services (MVP later)</div>
@endsection
