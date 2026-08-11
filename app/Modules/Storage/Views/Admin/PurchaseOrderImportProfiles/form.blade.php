@extends('layouts.default_tech')

@section('title', 'New Supplier Order Profile')

@section('sidebar')
    <x-nav.admin-menu group="storage" />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">New Supplier Order Profile</h1>
            <div class="small text-muted">Create an inactive draft and immutable first version.</div>
        </div>
        <x-buttons.back :url="route('tech.admin.settings.storage.supplier-order-profiles.index')" class="mb-0">Profiles</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        {{-- Profile identity and supplier ownership are mutable container metadata. --}}
        <form method="POST" action="{{ route('tech.admin.settings.storage.supplier-order-profiles.store') }}">
            @csrf
            <div class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0">Profile Identity</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" maxlength="255" class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name') }}" required autofocus>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="slug" class="form-label">Slug</label>
                            <input type="text" id="slug" name="slug" maxlength="255" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                                   class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug') }}" required>
                            @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="vendor_id" class="form-label">Supplier</label>
                            <select id="vendor_id" name="vendor_id" class="form-select">
                                <option value="">Generic profile</option>
                                @foreach($vendors as $vendor)
                                    <option value="{{ $vendor->id }}" @selected((int) old('vendor_id') === (int) $vendor->id)>{{ $vendor->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Only active supplier Vendors are selectable.</div>
                        </div>
                        <div class="col-md-3">
                            <label for="priority" class="form-label">Priority</label>
                            <input type="number" min="0" max="1000000" id="priority" name="priority" class="form-control"
                                   value="{{ old('priority', 100) }}" required>
                            <div class="form-text">Lower values are evaluated first.</div>
                        </div>
                        <div class="col-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea id="description" name="description" rows="3" maxlength="2000" class="form-control">{{ old('description') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Matching scope must exactly mirror the immutable definition match block. --}}
            <div class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0">Matching and Policy Overrides</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-xl-7">
                            <label for="matching_scope" class="form-label">Matching scope (JSON)</label>
                            <textarea id="matching_scope" name="matching_scope" rows="18"
                                      class="form-control font-monospace @error('matching_scope') is-invalid @enderror" required>{{ old('matching_scope', json_encode($matchingScope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>
                            @error('matching_scope')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Trusted authentication and alignment requirements should remain fail-closed.</div>
                        </div>
                        <div class="col-xl-5">
                            <label for="policy_overrides" class="form-label">Policy overrides (JSON)</label>
                            <textarea id="policy_overrides" name="policy_overrides" rows="18"
                                      class="form-control font-monospace @error('policy_overrides') is-invalid @enderror">{{ old('policy_overrides', '{}') }}</textarea>
                            @error('policy_overrides')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- Definitions are immutable after this version is created. --}}
            <div class="card mb-3">
                <div class="card-header">
                    <h2 class="h6 mb-0">Version 1 Definition</h2>
                    <div class="small text-muted">The definition is schema-validated and executable keys are rejected.</div>
                </div>
                <div class="card-body">
                    <label for="definition" class="form-label">Definition (JSON)</label>
                    <textarea id="definition" name="definition" rows="34"
                              class="form-control font-monospace @error('definition') is-invalid @enderror" required>{{ old('definition', json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) }}</textarea>
                    @error('definition')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="d-flex justify-content-end mb-4">
                <button type="submit" class="btn btn-primary">Create Draft Profile</button>
            </div>
        </form>
    </div>
@endsection
