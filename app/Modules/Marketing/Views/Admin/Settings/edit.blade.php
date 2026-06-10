@extends('layouts.default_tech')

@section('title', 'Marketing Settings')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Marketing Settings</h1>
        <x-buttons.back :url="route('tech.admin.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Marketing Settings Form -->
    <!-- ------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.settings.marketing.update') }}" class="d-grid gap-3">
        @csrf
        @method('PUT')

        @if(session('success'))
            <div class="alert alert-success py-2">{{ session('success') }}</div>
        @endif

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Consent And Unsubscribe</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label for="consent_mode" class="form-label">Consent mode</label>
                        <select id="consent_mode" name="consent_mode" class="form-select @error('consent_mode') is-invalid @enderror">
                            @foreach($consentModeOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('consent_mode', $settings['consent_mode']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('consent_mode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-6">
                        <label for="unsubscribe_mode" class="form-label">Unsubscribe mode</label>
                        <select id="unsubscribe_mode" name="unsubscribe_mode" class="form-select @error('unsubscribe_mode') is-invalid @enderror">
                            @foreach($unsubscribeModeOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('unsubscribe_mode', $settings['unsubscribe_mode']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('unsubscribe_mode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label for="unsubscribe_footer" class="form-label">Unsubscribe footer</label>
                        <textarea id="unsubscribe_footer" name="unsubscribe_footer" rows="3" class="form-control @error('unsubscribe_footer') is-invalid @enderror" maxlength="2000">{{ old('unsubscribe_footer', $settings['unsubscribe_footer']) }}</textarea>
                        @error('unsubscribe_footer') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <input type="hidden" name="active_contract_clients_eligible" value="0">
                        <div class="form-check">
                            <input type="checkbox" id="active_contract_clients_eligible" name="active_contract_clients_eligible" value="1" class="form-check-input" @checked(old('active_contract_clients_eligible', $settings['active_contract_clients_eligible']))>
                            <label for="active_contract_clients_eligible" class="form-check-label">Allow contacts for clients with active contracts to receive marketing</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="h6 mb-0">Sending And Tracking</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-3">
                        <label for="default_batch_size" class="form-label">Default batch size</label>
                        <input type="number" min="1" max="1000" id="default_batch_size" name="default_batch_size" class="form-control @error('default_batch_size') is-invalid @enderror" value="{{ old('default_batch_size', $settings['default_batch_size']) }}">
                        @error('default_batch_size') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="default_send_interval_minutes" class="form-label">Default interval minutes</label>
                        <input type="number" min="1" max="1440" id="default_send_interval_minutes" name="default_send_interval_minutes" class="form-control @error('default_send_interval_minutes') is-invalid @enderror" value="{{ old('default_send_interval_minutes', $settings['default_send_interval_minutes']) }}">
                        @error('default_send_interval_minutes') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="quiet_hours_start" class="form-label">Quiet hours start</label>
                        <input type="time" id="quiet_hours_start" name="quiet_hours_start" class="form-control @error('quiet_hours_start') is-invalid @enderror" value="{{ old('quiet_hours_start', $settings['quiet_hours_start']) }}">
                        @error('quiet_hours_start') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="quiet_hours_end" class="form-label">Quiet hours end</label>
                        <input type="time" id="quiet_hours_end" name="quiet_hours_end" class="form-control @error('quiet_hours_end') is-invalid @enderror" value="{{ old('quiet_hours_end', $settings['quiet_hours_end']) }}">
                        @error('quiet_hours_end') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-6">
                        <input type="hidden" name="open_tracking_enabled" value="0">
                        <div class="form-check">
                            <input type="checkbox" id="open_tracking_enabled" name="open_tracking_enabled" value="1" class="form-check-input" @checked(old('open_tracking_enabled', $settings['open_tracking_enabled']))>
                            <label for="open_tracking_enabled" class="form-check-label">Enable open tracking by default</label>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <input type="hidden" name="click_tracking_enabled" value="0">
                        <div class="form-check">
                            <input type="checkbox" id="click_tracking_enabled" name="click_tracking_enabled" value="1" class="form-check-input" @checked(old('click_tracking_enabled', $settings['click_tracking_enabled']))>
                            <label for="click_tracking_enabled" class="form-check-label">Enable click tracking by default</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.admin.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Settings</button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="marketing" />
@endsection

@section('rightbar')
    <!-- ------------------------------------------------- -->
    <!-- Marketing Settings Context -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Default Sender</h2>
        </div>
        <div class="card-body small">
            <p class="mb-2">
                Campaigns use the selected sender account. If none is selected, the active Email account marked for the marketing scope is used.
            </p>
            <dl class="row mb-0">
                <dt class="col-5">Current</dt>
                <dd class="col-7 text-end">{{ $marketingAccount?->address ?? 'Not configured' }}</dd>
            </dl>
        </div>
    </div>
@endsection
