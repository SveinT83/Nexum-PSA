@extends('layouts.default_tech')

@section('title', 'Edit intake form')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">{{ $form->name }}</h1>
    </div>
    <div class="col-auto d-flex flex-wrap gap-2">
        @if($form->isActive())
            <a href="{{ route('intake.forms.show', $form) }}" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                Public form
            </a>
        @endif
        <a href="{{ route('tech.admin.system.intake.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Back
        </a>
    </div>
@endsection

@section('content')
    @include('intake::Admin.forms.partials.form', [
        'action' => route('tech.admin.system.intake.forms.update', $form),
        'method' => 'PUT',
    ])
@endsection

@section('sidebar')
    <x-nav.admin-menu group="intake" />
@endsection

@section('rightbar')
@endsection
