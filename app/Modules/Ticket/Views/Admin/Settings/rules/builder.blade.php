@extends('layouts.default_tech')

@section('title', $ruleId ? 'Edit Ticket Rule' : 'Create Ticket Rule')

@section('pageHeader')
    {{-- Page header --}}
    <div class="d-flex align-items-center justify-content-between gap-2">
        <div>
            <h1 class="mb-1">{{ $ruleId ? 'Edit Ticket Rule' : 'Create Ticket Rule' }}</h1>
            <p class="text-muted mb-0">Build typed automation without raw identifiers or arbitrary JSON.</p>
        </div>
        <x-buttons.back url="{{ route('tech.admin.settings.tickets.rules') }}">Back</x-buttons.back>
    </div>
@endsection

@section('sidebar')
    {{-- Ticket settings navigation --}}
    <x-nav.admin-menu group="tickets" />
@endsection

@section('content')
    {{-- Ticket Rule builder --}}
    <div class="col-12">
        <livewire:tech.admin.tickets.rule-builder :rule-id="$ruleId" />
    </div>
@endsection

@section('rightbar')
    {{-- Publication safety --}}
    <x-card.default title="Publication safety">
        <p class="small text-muted">
            Drafts are isolated. Preview executes the typed runtime path with writes disabled.
            Publish creates a new immutable version and never activates runtime authority or release gates.
        </p>
        <p class="small text-muted mb-0">
            Unsupported legacy or future nodes remain read-only and are preserved exactly until explicitly removed.
        </p>
    </x-card.default>
@endsection
