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
                <a href="{{ route('tech.legal.edit', $term) }}" class="btn btn-sm btn-primary bi bi-pencil"> Edit</a>
            @endif

            <!-- Back button -->
            <a href="{{ route('tech.legal.index') }}" class="btn btn-sm btn-primary bi bi-backspace"> Back</a>
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

    <x-forms.form-default
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
            <!-- Cost Name -->
            <div class="col-md-4 mb-3">
                <x-forms.input_text name="name" labelName="Name:" value="{{$term->name ?? ''}}" inputVar="required {{$disabled}}"></x-forms.input_text>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Legal -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <!-- Cost Name -->
            <div class="col-md-12 mb-3">
                <x-forms.textarea name="legal" labelName="Legal" requried vars="{{$disabled}}">{{$term->legal ?? ''}}</x-forms.textarea>

                <p class="fw-lighter"><strong>Terms (commercial/service conditions):</strong> Use this for the service’s usage conditions—license, SLA, price adjustments, liability limits, acceptable use, termination. This belongs in the contract as the service terms.</p>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Term -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <!-- Cost Name -->
            <div class="col-md-12 mb-3">
                <x-forms.textarea name="term" labelName="Term" vars="{{$disabled}}">{{$term->term ?? ''}}</x-forms.textarea>

                <p class="fw-lighter"><strong>Legal (DPA/data processing):</strong> Use this for personal data handling—purpose, data categories, processing activities, sub-processors (e.g., Microsoft), transfers, security measures, retention, instructions, audit/oversight, any SCCs. This belongs in the data processing agreement.</p>
            </div>
        </div>

    </x-forms.form-default>

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent services (MVP later)</div>
@endsection
