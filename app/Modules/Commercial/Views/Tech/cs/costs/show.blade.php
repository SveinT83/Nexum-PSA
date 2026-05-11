@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Costs: {{ $cost->name }}</h2>
        <div>
            <a href="{{ route('tech.costs.edit', $cost )}}" class="btn btn-sm btn-secondary bi bi-pencil"> Edit</a>
            <a href="{{ route('tech.costs.index') }}" class="btn btn-sm btn-secondary bi bi-backspace"> Back</a>
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
    <!-- Cost profile data -->
    <!-- ------------------------------------------------- -->
    <div class="row justify-content-between">
        <p class="col"><i>Cost:</i> <b>{{$cost->cost}}</b> <i>Fore every</i> <b>{{$cost->unit->name}}</b> <i>pr.</i> <b>{{$cost->recurrence}}</b></p>
        <p class="col"><i>Description:</i> {{$cost->note}}</p>
    </div>



@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent services (MVP later)</div>
@endsection
