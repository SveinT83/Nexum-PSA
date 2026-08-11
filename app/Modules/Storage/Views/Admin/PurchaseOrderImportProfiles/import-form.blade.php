@extends('layouts.default_tech')

@section('title', 'Import Supplier Order Profile')

@section('sidebar')
    <x-nav.admin-menu group="storage" />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">Import Supplier Order Profile</h1>
            <div class="small text-muted">Imported profiles are always inactive drafts and require local validation.</div>
        </div>
        <x-buttons.back :url="route('tech.admin.settings.storage.supplier-order-profiles.index')" class="mb-0">Profiles</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        {{-- Import is bounded to the versioned export schema and never activates the result. --}}
        <form method="POST" action="{{ route('tech.admin.settings.storage.supplier-order-profiles.import.store') }}">
            @csrf
            <div class="card">
                <div class="card-header"><h2 class="h6 mb-0">Export JSON</h2></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="slug" class="form-label">Replacement slug (optional)</label>
                        <input type="text" id="slug" name="slug" maxlength="255" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                               class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug') }}">
                        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Use a new slug when the exported slug already exists.</div>
                    </div>
                    <div class="mb-3">
                        <label for="export" class="form-label">Profile export</label>
                        <textarea id="export" name="export" rows="30" class="form-control font-monospace @error('export') is-invalid @enderror"
                                  placeholder="{&#10;  &quot;schema_version&quot;: &quot;storage.supplier_order_profile_export.v1&quot;&#10;}" required>{{ old('export') }}</textarea>
                        @error('export')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="alert alert-light border small">
                        Import verifies the export envelope and definition checksum. It creates a draft container and draft immutable version;
                        activation still requires local protected fixtures and an explicit reason.
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">Import as Draft</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection
