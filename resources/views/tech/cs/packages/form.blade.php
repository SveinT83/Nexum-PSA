@extends('layouts.default_tech')

<!-- Edit or new Package Policy? -->
@php
    $isEdit = $package->exists && !request()->routeIs('tech.packages.show');
    $isShow = request()->routeIs('tech.packages.show');
    $disabled = $isShow ? 'disabled' : null;
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">{{ $isShow ? 'Package details' : ($isEdit ? 'Edit package' : 'New package') }}</h2>
        <div>
            <a href="{{ route('tech.packages.index') }}" class="btn btn-sm btn-primary bi bi-backspace"> Back</a>
        </div>
    </div>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- FORM - Start the form -->
    <!-- ------------------------------------------------- -->
    <x-forms.form-default
        action="{{ $isShow
        ? route('tech.packages.edit', $package)
        : ($isEdit ? route('tech.packages.update', $package) : route('tech.packages.store')) }}"
        method="{{ $isShow ? 'get' : 'post' }}"
        button-text="{{ $isEdit ? 'Update' : ($isShow ? 'Edit' : 'Save') }}">

        @if($isEdit)
            @method('PUT')
        @endif


        <!-- ------------------------------------------------- -->
        <!-- Details -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Details">

            <!-- ------------------------------------------------- -->
            <!-- Name -->
            <!-- ------------------------------------------------- -->
            <div class="row mt-2">
                <div class="col-md-12">
                    <x-forms.input_text name="name" labelName="Package name" value="{{$package->name ?? ''}}" inputVar="required {{$disabled ?? ''}}"></x-forms.input_text>
                </div>
            </div>

            <!-- ------------------------------------------------- -->
            <!-- Description -->
            <!-- ------------------------------------------------- -->
            <div class="row mt-2">
                <div class="col-md-12">
                    <x-forms.textarea name="description" labelName="Description" vars="{{$disabled ?? ''}}">{{$package->description ?? ''}}</x-forms.textarea>
                </div>
            </div>
        </x-card.default>

        <!-- ------------------------------------------------- -->
        <!-- Services -->
        <!-- ------------------------------------------------- -->

        <livewire:tech.cs.service-picker :package="$package ?? null" :enabled="$disabled ?? 'enabled'" />

        <!-- ------------------------------------------------- -->
        <!-- Legal -->
        <!-- ------------------------------------------------- -->
        <livewire:tech.cs.package-legal :package="$package ?? null" :enabled="$disabled ?? 'enabled'" />

        <!-- ------------------------------------------------- -->
        <!-- Pricing -->
        <!-- ------------------------------------------------- -->
        <livewire:tech.cs.package-pricing :package="$package ?? null" :enabled="$disabled ?? 'enabled'" />

        <!-- ------------------------------------------------- -->
        <!-- FORM - End the form -->
        <!-- ------------------------------------------------- -->
    </x-forms.form-default>

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Packages filters (later)</div>
@endsection

@section('rightbar')
    <div class="p-3 small text-muted">Recent Packages (MVP later)</div>
@endsection
