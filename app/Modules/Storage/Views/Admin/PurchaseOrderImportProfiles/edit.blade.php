@extends('layouts.default_tech')

@section('title', 'Edit Supplier Profile Metadata')

@section('sidebar')
    <x-nav.admin-menu group="storage" />
@endsection

@section('pageHeader')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">Edit Supplier Profile Metadata</h1>
            <div class="small text-muted">{{ $profile->name }} / audited container fields</div>
        </div>
        <x-buttons.back :url="route('tech.admin.settings.storage.supplier-order-profiles.show', $profile)" class="mb-0">Profile</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @php
            $scopeValue = old('matching_scope', $profile->matching_scope);
            if (is_array($scopeValue)) {
                $scopeValue = json_encode($scopeValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        @endphp

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="alert alert-info">
            <strong>Immutable parser boundary:</strong>
            this form changes audited profile-container metadata only. Runtime matching and extraction continue to use
            the active immutable version's <code>definition.match</code> block. Create, test, and activate a new version
            when runtime behavior must change.
        </div>

        {{-- Identity and descriptive fields are mutable, unlike parser versions. --}}
        <form method="POST" action="{{ route('tech.admin.settings.storage.supplier-order-profiles.update', $profile) }}">
            @csrf
            @method('PUT')

            <div class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0">Profile Container</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" maxlength="255"
                                   class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $profile->name) }}" required autofocus>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="slug" class="form-label">Slug</label>
                            <input type="text" id="slug" name="slug" maxlength="255"
                                   pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                                   class="form-control @error('slug') is-invalid @enderror"
                                   value="{{ old('slug', $profile->slug) }}" required>
                            @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea id="description" name="description" rows="3" maxlength="2000"
                                      class="form-control @error('description') is-invalid @enderror">{{ old('description', $profile->description) }}</textarea>
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label for="matching_scope" class="form-label">Container matching scope (JSON)</label>
                            <textarea id="matching_scope" name="matching_scope" rows="16"
                                      class="form-control font-monospace @error('matching_scope') is-invalid @enderror"
                                      required>{{ $scopeValue }}</textarea>
                            @error('matching_scope')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Validated with the same bounded selector allowlist as version definitions; does not alter active runtime matching.</div>
                        </div>
                        <div class="col-12">
                            <label for="reason" class="form-label">Change reason</label>
                            <textarea id="reason" name="reason" rows="2" minlength="5" maxlength="245"
                                      class="form-control @error('reason') is-invalid @enderror" required>{{ old('reason') }}</textarea>
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Stored with the actor and complete before/after metadata snapshots.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end mb-4">
                <button type="submit" class="btn btn-primary">Save Audited Metadata</button>
            </div>
        </form>
    </div>
@endsection
