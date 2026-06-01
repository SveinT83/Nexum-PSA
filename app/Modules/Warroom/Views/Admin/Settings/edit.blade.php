@extends('layouts.default_tech')

@section('title', 'Warroom Settings')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Warroom Settings</h1>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Warroom Settings Form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.settings.warroom.update') }}">
        @csrf
        @method('PUT')

        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Operational Windows</h2>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif

                <div class="row g-3">
                    <div class="col-lg-6">
                        <label for="due_soon_hours" class="form-label">SLA due soon window</label>
                        <div class="input-group">
                            <input type="number" min="1" max="168" class="form-control @error('due_soon_hours') is-invalid @enderror" id="due_soon_hours" name="due_soon_hours" value="{{ old('due_soon_hours', $settings['due_soon_hours']) }}" required>
                            <span class="input-group-text">hours</span>
                            @error('due_soon_hours') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <label for="inbox_recent_hours" class="form-label">Inbox recent window</label>
                        <div class="input-group">
                            <input type="number" min="1" max="168" class="form-control @error('inbox_recent_hours') is-invalid @enderror" id="inbox_recent_hours" name="inbox_recent_hours" value="{{ old('inbox_recent_hours', $settings['inbox_recent_hours']) }}" required>
                            <span class="input-group-text">hours</span>
                            @error('inbox_recent_hours') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">List Limits</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach([
                        'latest_tickets_limit' => 'Latest tickets',
                        'latest_alerts_limit' => 'Latest asset alerts',
                        'calendar_today_limit' => 'Calendar events today',
                        'recent_integrations_limit' => 'Recent integrations',
                    ] as $field => $label)
                        <div class="col-sm-6 col-xl-3">
                            <label for="{{ $field }}" class="form-label">{{ $label }}</label>
                            <input type="number" min="1" max="20" class="form-control @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $settings[$field]) }}" required>
                            @error($field) <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Visible Panels</h2>
            </div>
            <div class="card-body">
                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2">
                    @foreach($sectionOptions as $value => $label)
                        <div class="col">
                            <div class="form-check border rounded p-3 h-100">
                                <input
                                    class="form-check-input ms-0 me-2"
                                    type="checkbox"
                                    id="enabled_section_{{ $value }}"
                                    name="enabled_sections[]"
                                    value="{{ $value }}"
                                    @checked(in_array($value, old('enabled_sections', $settings['enabled_sections']), true))
                                >
                                <label class="form-check-label" for="enabled_section_{{ $value }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('enabled_sections') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                @error('enabled_sections.*') <div class="text-danger small mt-2">{{ $message }}</div> @enderror

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="{{ route('tech.admin.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="warroom" />
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
                Warroom settings control the hardcoded operational dashboard used during beta.
            </p>
            <p class="mb-0">
                Technician-custom dashboards are planned later. These settings are global.
            </p>
        </div>
    </div>
@endsection
