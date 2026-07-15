@extends('layouts.default_tech')

@section('title', $profile?->exists ? 'Edit Data Exchange Profile' : 'Create Data Exchange Profile')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">{{ $profile?->exists ? 'Edit Data Exchange Profile' : 'Create Data Exchange Profile' }}</h1>
        <x-buttons.back :url="route('tech.admin.system.data-exchange.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Data Builder -->
    <!-- ------------------------------------------------- -->
    <livewire:tech.admin.system.data-exchange.profile-builder :profile="$profile" />
@endsection

@section('sidebar')
    <x-nav.admin-menu group="data-exchange" />
@endsection

@section('rightbar')
    <x-card.default title="Builder Rules">
        <div class="small text-muted d-grid gap-2">
            <p class="mb-0">Profiles use registered sources only. Raw SQL is not available.</p>
            <p class="mb-0">Secret-like fields are blocked in code before they can be selected.</p>
            <p class="mb-0">Imports can commit only through module-approved targets.</p>
        </div>
    </x-card.default>
@endsection
