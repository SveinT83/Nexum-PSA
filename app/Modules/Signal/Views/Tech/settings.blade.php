@extends('layouts.default_tech')

@section('title', 'Signal Settings')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">Signal Settings</h1>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('tech.admin.system.signals.index') }}" class="btn btn-sm btn-outline-secondary">Signals</a>
            <a href="{{ route('tech.admin.system.signals.rules.index') }}" class="btn btn-sm btn-outline-secondary">Rules</a>
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Signal settings -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('tech.admin.system.signals.settings.update') }}" class="d-grid gap-3">
        @csrf
        @method('PUT')

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span class="fw-semibold">AI Classification</span>
                <span class="badge text-bg-{{ $settings['ai_classification_enabled'] ? 'success' : 'light' }} border">
                    {{ $settings['ai_classification_enabled'] ? 'Enabled' : 'Disabled' }}
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <input type="hidden" name="ai_classification_enabled" value="0">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="ai_classification_enabled" name="ai_classification_enabled" value="1" @checked(old('ai_classification_enabled', $settings['ai_classification_enabled']))>
                            <label class="form-check-label" for="ai_classification_enabled">Use AI classification</label>
                        </div>

                        <label for="ai_min_confidence" class="form-label">Minimum confidence</label>
                        <input type="number" min="0" max="100" id="ai_min_confidence" name="ai_min_confidence" class="form-control @error('ai_min_confidence') is-invalid @enderror" value="{{ old('ai_min_confidence', $settings['ai_min_confidence']) }}" required>
                        @error('ai_min_confidence')<div class="invalid-feedback">{{ $message }}</div>@enderror

                        <div class="mt-3 small">
                            <div class="text-muted text-uppercase mb-1">Agent</div>
                            @if($agent)
                                <div class="fw-semibold">{{ $agent->name }}</div>
                                <div class="text-muted">{{ $agent->provider?->name ?? 'No provider' }} / {{ $agent->model ?: $agent->provider?->default_model }}</div>
                            @else
                                <div class="text-muted">No active Signal AI agent.</div>
                            @endif
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <label for="ai_source_domains" class="form-label">AI source domains</label>
                        <textarea id="ai_source_domains" name="ai_source_domains" rows="5" class="form-control @error('ai_source_domains') is-invalid @enderror">{{ old('ai_source_domains', $settingsSupport->listToText($settings['ai_source_domains'])) }}</textarea>
                        @error('ai_source_domains')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="ai_allowed_signal_types" class="form-label">Allowed AI signal types</label>
                        <textarea id="ai_allowed_signal_types" name="ai_allowed_signal_types" rows="5" class="form-control @error('ai_allowed_signal_types') is-invalid @enderror">{{ old('ai_allowed_signal_types', $settingsSupport->listToText($settings['ai_allowed_signal_types'])) }}</textarea>
                        @error('ai_allowed_signal_types')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-4">
                        <label for="ai_stop_ticket_routing_types" class="form-label">Skip ticket routing types</label>
                        <textarea id="ai_stop_ticket_routing_types" name="ai_stop_ticket_routing_types" rows="6" class="form-control @error('ai_stop_ticket_routing_types') is-invalid @enderror">{{ old('ai_stop_ticket_routing_types', $settingsSupport->listToText($settings['ai_stop_ticket_routing_types'])) }}</textarea>
                        @error('ai_stop_ticket_routing_types')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-8">
                        <label for="ai_classification_prompt" class="form-label">AI classification prompt</label>
                        <textarea id="ai_classification_prompt" name="ai_classification_prompt" rows="12" class="form-control font-monospace @error('ai_classification_prompt') is-invalid @enderror">{{ old('ai_classification_prompt', $settings['ai_classification_prompt']) }}</textarea>
                        @error('ai_classification_prompt')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.admin.system.signals.index') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-sm btn-primary">Save Settings</button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
