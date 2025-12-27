@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">New service</h2>
        <div>
            <a href="{{ route('tech.services.index') }}" class="btn btn-sm btn-primary">Back</a>
        </div>
    </div>
@endsection

@section('content')

    <x-forms.create-service-form title="New service" enabled="enabled"></x-forms.create-service-form>

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent services (MVP later)</div>
@endsection
