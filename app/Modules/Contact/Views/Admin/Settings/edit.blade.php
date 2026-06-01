@extends('layouts.default_tech')

@section('title', 'Contact Settings')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Contact Settings</h1>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Contact Settings Form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.settings.contacts.update') }}">
        @csrf
        @method('PUT')

        <div class="card shadow-sm mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Contact Defaults</h2>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif

                <div class="row g-3">
                    <div class="col-lg-4">
                        <label for="default_contact_type" class="form-label">Default contact type</label>
                        <select id="default_contact_type" name="default_contact_type" class="form-select @error('default_contact_type') is-invalid @enderror">
                            @foreach($contactTypeOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_contact_type', $settings['default_contact_type']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_contact_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-4">
                        <label for="default_status" class="form-label">Default status</label>
                        <select id="default_status" name="default_status" class="form-select @error('default_status') is-invalid @enderror">
                            @foreach($statusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_status', $settings['default_status']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_status') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-lg-4">
                        <label for="default_relation_type" class="form-label">Default relation</label>
                        <select id="default_relation_type" name="default_relation_type" class="form-select @error('default_relation_type') is-invalid @enderror">
                            @foreach($relationOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('default_relation_type', $settings['default_relation_type']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('default_relation_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Relation Types</h2>
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    Enabled relation types are shown in the Contact form when a contact is linked to a client or site.
                </p>

                <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2">
                    @foreach($relationOptions as $value => $label)
                        <div class="col">
                            <div class="form-check border rounded p-3 h-100">
                                <input
                                    class="form-check-input ms-0 me-2"
                                    type="checkbox"
                                    id="enabled_relation_type_{{ $value }}"
                                    name="enabled_relation_types[]"
                                    value="{{ $value }}"
                                    @checked(in_array($value, old('enabled_relation_types', $settings['enabled_relation_types']), true))
                                >
                                <label class="form-check-label" for="enabled_relation_type_{{ $value }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('enabled_relation_types') <div class="text-danger small mt-2">{{ $message }}</div> @enderror
                @error('enabled_relation_types.*') <div class="text-danger small mt-2">{{ $message }}</div> @enderror

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="{{ route('tech.admin.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="contacts" />
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
                Duplicate protection by email and phone remains mandatory. These settings only control defaults and relation choices.
            </p>
            <p class="mb-0">
                Language defaults will be added after the system-wide language settings decision.
            </p>
        </div>
    </div>
@endsection
