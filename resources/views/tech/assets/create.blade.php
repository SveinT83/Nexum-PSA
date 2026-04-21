@extends('layouts.default_tech')

@section('title', 'Create Asset')

{{--
    Asset Creation Page
    -------------------
    This view serves as the entry point for manually creating new assets in the system.
    It utilizes the 'default_tech' layout and integrates the AssetForm Livewire component
    for a dynamic, multi-step-like form experience.
--}}

@section('pageHeader')

    {{-- Breadcrumb Navigation for user orientation --}}
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.assets.index') }}">Assets</a></li>
            <li class="breadcrumb-item active" aria-current="page">Create</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center">
        <h1>Create Asset</h1>
        <div class="btn-group">
            <x-buttons.back :url="route('tech.assets.index')">Back to Assets</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    {{--
                        Livewire Component: AssetForm
                        Handles:
                        - Dynamic loading of Sites and Users based on selected Client.
                        - Real-time validation of asset attributes.
                        - Submission and creation of the Asset record.
                    --}}
                    @livewire('tech.assets.asset-form')
                </div>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')

    {{-- Sidebar Information Card --}}
    <div class="card border-info">
        <div class="card-body">
            <h5 class="card-title text-info"><i class="bi bi-info-circle"></i> Asset Creation</h5>
            <p class="card-text small">
                Assets created manually here can later be linked to RMM data using <strong>Serial Number</strong>, <strong>MAC Address</strong>, or <strong>Hostname</strong>.
            </p>
            <p class="card-text small mb-0">
                Fields marked with * are required.
            </p>
        </div>
    </div>
@endsection
