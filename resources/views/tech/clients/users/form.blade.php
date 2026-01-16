@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">New user for: {{ $client->name }} {{ $client->site }}</h2>
        <div>
            <a href="{{ route('tech.clients.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>
@endsection

@section('content')

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Client nav (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Widgets (later)</div>
@endsection

