@extends('layouts.default_tech')

@section('title', 'Risk Settings')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Risk Settings</h1>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Risk Settings Form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.settings.risk.update') }}">
        @csrf
        @method('PUT')

        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Assessment Defaults</h2>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif

                <div class="row g-3">
                    <div class="col-lg-6">
                        <label for="default_assessment_scope" class="form-label">Default scope</label>
                        <select id="default_assessment_scope" name="default_assessment_scope" class="form-select @error('default_assessment_scope') is-invalid @enderror">
                            @foreach($assessmentScopeOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_assessment_scope', $settings['default_assessment_scope']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_assessment_scope') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-6">
                        <label for="default_assessment_status" class="form-label">Default status</label>
                        <select id="default_assessment_status" name="default_assessment_status" class="form-select @error('default_assessment_status') is-invalid @enderror">
                            @foreach($assessmentStatusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_assessment_status', $settings['default_assessment_status']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_assessment_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Risk Item Defaults</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-3">
                        <label for="default_item_likelihood" class="form-label">Likelihood</label>
                        <input type="number" min="1" max="5" class="form-control @error('default_item_likelihood') is-invalid @enderror" id="default_item_likelihood" name="default_item_likelihood" value="{{ old('default_item_likelihood', $settings['default_item_likelihood']) }}" required>
                        @error('default_item_likelihood') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-3">
                        <label for="default_item_impact" class="form-label">Impact</label>
                        <input type="number" min="1" max="5" class="form-control @error('default_item_impact') is-invalid @enderror" id="default_item_impact" name="default_item_impact" value="{{ old('default_item_impact', $settings['default_item_impact']) }}" required>
                        @error('default_item_impact') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-3">
                        <label for="default_item_status" class="form-label">Status</label>
                        <select id="default_item_status" name="default_item_status" class="form-select @error('default_item_status') is-invalid @enderror">
                            @foreach($itemStatusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_item_status', $settings['default_item_status']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_item_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-3">
                        <label for="default_item_review_days" class="form-label">Review after</label>
                        <div class="input-group">
                            <input type="number" min="1" max="3650" class="form-control @error('default_item_review_days') is-invalid @enderror" id="default_item_review_days" name="default_item_review_days" value="{{ old('default_item_review_days', $settings['default_item_review_days']) }}" required>
                            <span class="input-group-text">days</span>
                            @error('default_item_review_days') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
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
    <x-nav.admin-menu group="risk" />
@endsection

@section('rightbar')
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Documentation / Help</h2>
        </div>
        <div class="card-body small">
            <p>
                Risk settings control defaults for new assessments and newly identified risk items.
            </p>
            <p class="mb-0">
                Existing risk items keep their historical likelihood, impact, and status values.
            </p>
        </div>
    </div>
@endsection
