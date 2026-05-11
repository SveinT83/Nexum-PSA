@extends('layouts.default_tech')

<!-- Edit or new SLA Policy? -->
@php
    $isEdit = isset($sla) && !request()->routeIs('tech.sla.show');
    $isShow = request()->routeIs('tech.sla.show');
    $disabled = $isShow ? 'disabled' : null;
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Create / Edit SLA</h2>


        <div>
            <!-- Edit button -->
            @if($isShow && isset($sla))
                <a href="{{ route('tech.sla.edit', $sla) }}" class="btn btn-sm btn-primary bi bi-pencil"> Edit</a>
            @endif

            <!-- Back button -->
            <a href="{{ route('tech.sla.index') }}" class="btn btn-sm btn-primary bi bi-backspace"> Back</a>
        </div>

    </div>
@endsection

@section('content')

    <x-forms.form-default
        action="{{ $isShow
        ? route('tech.sla.edit', $sla ?? null)
        : ($isEdit ? route('tech.sla.update', $sla ?? null) : route('tech.sla.store')) }}"
        method="{{ $isShow ? 'get' : 'post' }}"
        button-text="{{ $isEdit ? 'Update' : ($isShow ? 'Edit' : 'Save') }}">

        @if($isEdit)
            @method('PUT')
        @endif

        <!-- ------------------------------------------------- -->
        <!-- SLA Data -->
        <!-- ------------------------------------------------- -->
        <div class="row mt-3">
            <h2>Descriptions</h2>
        </div>

        <div class="row mt-2">
            <!-- SLA Name -->
            <div class="col-md-4 mb-3">
                <x-forms.input_text name="name" labelName="Name:" value="{{$sla->name ?? ''}}" inputVar="required {{$disabled ?? ''}}"></x-forms.input_text>
            </div>

        </div>
        <div class="row mt-2">

            <div class="col-md-8 mb-3">
                <x-forms.textarea name="description" labelName="Description" vars="{{$disabled ?? ''}}">{{$sla->description ?? ''}}</x-forms.textarea>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Response Time Form -->
        <!-- ------------------------------------------------- -->
        @include("tech.cs.sla.partials.responseTime", ['sla' => $sla ?? null])

    </x-forms.form-default>
@endsection

@section('sidebar')
    <div class="p-3 small text-muted">SLA filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent SLA (MVP later)</div>
@endsection
