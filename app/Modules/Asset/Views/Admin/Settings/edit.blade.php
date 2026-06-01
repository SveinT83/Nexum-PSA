@extends('layouts.default_tech')

@section('title', 'Asset Settings')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Asset Settings</h1>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Asset Settings Form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.settings.assets.update') }}">
        @csrf
        @method('PUT')

        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Manual Asset Defaults</h2>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif

                <div class="row g-3">
                    <div class="col-lg-6">
                        <label for="default_type" class="form-label">Default asset type</label>
                        <select id="default_type" name="default_type" class="form-select @error('default_type') is-invalid @enderror">
                            @foreach($typeOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_type', $settings['default_type']) === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('default_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-3">
                        <label for="default_ip_type" class="form-label">Default IP mode</label>
                        <select id="default_ip_type" name="default_ip_type" class="form-select @error('default_ip_type') is-invalid @enderror">
                            @foreach($ipTypeOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_ip_type', $settings['default_ip_type']) === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('default_ip_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-3">
                        <label for="default_status" class="form-label">Default manual status</label>
                        <select id="default_status" name="default_status" class="form-select @error('default_status') is-invalid @enderror">
                            @foreach($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_status', $settings['default_status']) === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('default_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Enabled Asset Types</h2>
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    These values control which existing system asset types are available in the manual Asset form.
                </p>

                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-2">
                    @foreach($typeOptions as $value => $label)
                        <div class="col">
                            <div class="form-check border rounded p-3 h-100">
                                <input
                                    class="form-check-input ms-0 me-2"
                                    type="checkbox"
                                    id="enabled_type_{{ $value }}"
                                    name="enabled_types[]"
                                    value="{{ $value }}"
                                    @checked(in_array($value, old('enabled_types', $settings['enabled_types']), true))
                                >
                                <label class="form-check-label" for="enabled_type_{{ $value }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('enabled_types') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                @error('enabled_types.*') <div class="text-danger small mt-2">{{ $message }}</div> @enderror

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="{{ route('tech.admin.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="assets" />
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
                Asset settings currently affect manual asset registration. RMM imports keep their integration-owned behavior.
            </p>
            <p class="mb-0">
                New asset type values require a schema change because the current asset type column is constrained.
            </p>
        </div>
    </div>
@endsection
