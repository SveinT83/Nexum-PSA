@extends('layouts.default_tech')

@section('title', 'New intake form')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">New intake form</h1>
    </div>
    <div class="col-auto">
        <a href="{{ route('tech.admin.system.intake.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Back
        </a>
    </div>
@endsection

@section('content')
    @include('intake::Admin.forms.partials.form', [
        'action' => route('tech.admin.system.intake.forms.store'),
        'method' => 'POST',
    ])
@endsection

@section('sidebar')
    <x-nav.admin-menu group="intake" />
@endsection

@section('rightbar')
@endsection
