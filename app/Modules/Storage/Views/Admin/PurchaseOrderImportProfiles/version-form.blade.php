@extends('layouts.default_tech')

@section('title', 'New Version - ' . $profile->name)

@section('sidebar')
    <x-nav.admin-menu group="storage" />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">New Version for {{ $profile->name }}</h1>
            <div class="small text-muted">
                {{ $sourceVersion ? 'Clone immutable version '.$sourceVersion->version_number : 'Create a new first definition' }}
            </div>
        </div>
        <x-buttons.back :url="route('tech.admin.settings.storage.supplier-order-profiles.show', $profile)" class="mb-0">Profile</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        {{-- A saved version is immutable; future changes require another clone. --}}
        <form method="POST" action="{{ route('tech.admin.settings.storage.supplier-order-profiles.versions.store', $profile) }}">
            @csrf
            @if($sourceVersion)
                <input type="hidden" name="parent_version_id" value="{{ $sourceVersion->id }}">
            @endif

            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <div>
                        <h2 class="h6 mb-0">Definition JSON</h2>
                        <div class="small text-muted">Schema, safety, and checksum validation runs before persistence.</div>
                    </div>
                    @if($sourceVersion)
                        <span class="badge text-bg-light border">Parent v{{ $sourceVersion->version_number }}</span>
                    @endif
                </div>
                <div class="card-body">
                    <textarea id="definition" name="definition" rows="40"
                              class="form-control font-monospace @error('definition') is-invalid @enderror" required>{{ old('definition', json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>
                    @error('definition')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Save Draft Version</button>
            </div>
        </form>
    </div>
@endsection
