@extends('layouts.default_tech')

@section('title', 'Knowledge Settings')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Knowledge Settings</h1>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Knowledge Settings Form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.settings.knowledge.update') }}">
        @csrf
        @method('PUT')

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Article Defaults</h2>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif

                <div class="row g-3">
                    <div class="col-lg-3">
                        <label for="default_visibility" class="form-label">Default visibility</label>
                        <select id="default_visibility" name="default_visibility" class="form-select @error('default_visibility') is-invalid @enderror">
                            @foreach($visibilityOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_visibility', $settings['default_visibility']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_visibility') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-3">
                        <label for="default_status" class="form-label">Default status</label>
                        <select id="default_status" name="default_status" class="form-select @error('default_status') is-invalid @enderror">
                            @foreach($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_status', $settings['default_status']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-3">
                        <label for="default_review_days" class="form-label">Review after</label>
                        <div class="input-group">
                            <input type="number" min="1" max="3650" class="form-control @error('default_review_days') is-invalid @enderror" id="default_review_days" name="default_review_days" value="{{ old('default_review_days', $settings['default_review_days']) }}" required>
                            <span class="input-group-text">days</span>
                            @error('default_review_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="col-lg-3">
                        <label for="default_priority" class="form-label">Default sort priority</label>
                        <input type="number" min="0" max="100000" class="form-control @error('default_priority') is-invalid @enderror" id="default_priority" name="default_priority" value="{{ old('default_priority', $settings['default_priority']) }}" required>
                        @error('default_priority') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="{{ route('tech.admin.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="knowledge" />
@endsection

@section('rightbar')
    <!-- ------------------------------------------------- -->
    <!-- Settings Help -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Documentation / Help</h2>
        </div>
        <div class="card-body small">
            <p>
                These settings affect manually created Knowledge articles and pages.
            </p>
            <p class="mb-0">
                BookStack connection behavior is managed under Integrations because it belongs to the integration.
            </p>
        </div>
    </div>
@endsection
