@extends('layouts.default_tech')

<!-- Edit or new Term or Legal Policy? -->
@php
    $isEdit = isset($term) && !request()->routeIs('tech.legal.show');
    $isShow = request()->routeIs('tech.legal.show');
    $disabled = $isShow ? 'disabled' : null;
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">New Term og /and Legal</h2>

        <div>
            <!-- Edit button -->
            @if($isShow && isset($term))
                <a href="{{ route('tech.legal.edit', $term) }}" class="btn btn-sm btn-outline-warning bi bi-pencil"> Edit</a>
            @endif

            <!-- Back button -->
            <a href="{{ route('tech.legal.index') }}" class="btn btn-sm btn-secondary bi bi-backspace"> Back</a>
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
    <!-- Form -->
    <!-- ------------------------------------------------- -->

    <x-forms.form-card
        title="Term og /and Legal"
        action="{{ $isShow
        ? route('tech.legal.edit', $term ?? null)
        : ($isEdit ? route('tech.legal.update', $term ?? null) : route('tech.legal.store')) }}"
        method="{{ $isShow ? 'get' : 'post' }}"
        button-text="{{ $isEdit ? 'Update' : ($isShow ? 'Edit' : 'Save') }}">

        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Name -->
        <!-- ------------------------------------------------- -->
        <div class="row">

            <!-- Name -->
            <div class="col-md-4 mb-3">
                <x-forms.input_text name="name" labelName="Name:" value="{{$term->name ?? ''}}" inputVar="required {{$disabled}}"></x-forms.input_text>
            </div>

            <!-- type -->
            <div class="col-md-4 mb-3">
                <x-forms.select name="type" labelName="Type:" inputVar="required {{$disabled}}">
                    <option value="terms" {{ (isset($term) && $term->type == 'terms') ? 'selected' : '' }}>Terms</option>
                    <option value="dpa" {{ (isset($term) && $term->type == 'dpa') ? 'selected' : '' }}>DPA</option>
                    <option value="legal" {{ (isset($term) && $term->type == 'legal') ? 'selected' : '' }}>Legal</option>
                    <option value="sla" {{ (isset($term) && $term->type == 'sla') ? 'selected' : '' }}>SLA</option>
                    <option value="general" {{ (isset($term) && $term->type == 'general') ? 'selected' : '' }}>General</option>
                </x-forms.select>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Content -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <!-- Cost Name -->
            <div class="col-md-12 mb-3">
                <x-forms.textarea name="content" labelName="Content" vars="{{$disabled}}">{{$term->content ?? ''}}</x-forms.textarea>
            </div>
        </div>

    </x-forms.form-card>

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

@section('rightbar')

    <!-- ------------------------------------------------- -->
    <!-- Services -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Connected Services">
        @if(isset($term) && $term->services->count() > 0)
            <ul class="list-group list-group-flush">
                @foreach($term->services as $service)
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <div>
                            <span class="fw-bold d-block">{{ $service->name }}</span>
                            <small class="text-muted">{{ $service->sku }}</small>
                        </div>
                        <a href="{{ route('tech.services.edit', $service) }}" class="btn btn-sm btn-link p-0 bi bi-box-arrow-in-right"></a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-muted mb-0">No services connected to this term.</p>
        @endif
    </x-card.default>

@endsection
