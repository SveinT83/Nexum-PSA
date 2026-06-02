@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Client Settings</h1>
        <div>
            <x-buttons.back url="{{ route('tech.clients.show', $client->id) }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Client settings context -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h5 mb-0">{{ $client->name }}</h2>
        </div>
    </div>

    {{-- N-able RMM Link Card --}}
    @if($rmmIntegration && $rmmIntegration->status === 'active')
        <div class="card mb-3">
            <div class="card-header">
                <h2 class="h5 mb-0">N-able RMM Integration</h2>
            </div>
            <div class="card-body">
                @if(isset($rmmError))
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>Warning:</strong> Could not fetch clients from N-able RMM.
                        <p class="mb-0 small text-muted mt-1">{{ $rmmError }}</p>
                    </div>
                @endif

                <form action="{{ route('tech.clients.settings.update', $client->id) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="rmm_external_id" class="form-label">Link to N-able RMM Client (Mapping)</label>
                        <select name="rmm_external_id" id="rmm_external_id" class="form-select" {{ isset($rmmError) ? 'disabled' : '' }}>
                            <option value="">-- {{ isset($rmmError) ? 'Error fetching clients' : 'Select RMM Client' }} --</option>
                            @foreach($rmmClients as $rmmClient)
                                <option value="{{ $rmmClient['clientid'] }}" {{ ($currentRmmId ?? '') == $rmmClient['clientid'] ? 'selected' : '' }}>
                                    {{ $rmmClient['name'] }} ({{ $rmmClient['clientid'] }})
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">Select the corresponding client in N-able RMM to enable synchronization.</small>
                    </div>

                    <button type="submit" class="btn btn-primary" {{ isset($rmmError) ? 'disabled' : '' }}>Save Settings</button>
                </form>
            </div>
        </div>
    @endif

    {{-- Custom Fields --}}
    @if(($customFields ?? collect())->isNotEmpty())
        <form action="{{ route('tech.clients.settings.update', $client->id) }}" method="POST" class="card mb-3">
            @csrf
            @method('PUT')
            <div class="card-header">
                <h2 class="h5 mb-0">Custom Fields</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach($customFields as $field)
                        @php($definition = $field['definition'])
                        <div class="col-md-6">
                            <label class="form-label" for="customField{{ $definition->id }}">
                                {{ $field['label'] }}
                                @if($field['required'])
                                    <span class="text-danger">*</span>
                                @endif
                            </label>

                            @if(in_array($field['type'], ['textarea'], true))
                                <textarea id="customField{{ $definition->id }}" name="custom_fields[{{ $field['key'] }}]" rows="3" class="form-control @error('custom_fields.'.$field['key']) is-invalid @enderror">{{ old('custom_fields.'.$field['key'], $field['value']) }}</textarea>
                            @elseif($field['type'] === 'select')
                                <select id="customField{{ $definition->id }}" name="custom_fields[{{ $field['key'] }}]" class="form-select @error('custom_fields.'.$field['key']) is-invalid @enderror">
                                    <option value="">—</option>
                                    @foreach($field['options'] as $option)
                                        <option value="{{ $option }}" @selected(old('custom_fields.'.$field['key'], $field['value']) === $option)>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @elseif($field['type'] === 'multiselect')
                                <select id="customField{{ $definition->id }}" name="custom_fields[{{ $field['key'] }}][]" class="form-select @error('custom_fields.'.$field['key']) is-invalid @enderror" multiple>
                                    @foreach($field['options'] as $option)
                                        <option value="{{ $option }}" @selected(in_array($option, old('custom_fields.'.$field['key'], $field['value'] ?? []), true))>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @elseif($field['type'] === 'checkbox')
                                <input type="hidden" name="custom_fields[{{ $field['key'] }}]" value="0">
                                <div class="form-check">
                                    <input id="customField{{ $definition->id }}" type="checkbox" name="custom_fields[{{ $field['key'] }}]" value="1" class="form-check-input @error('custom_fields.'.$field['key']) is-invalid @enderror" @checked(old('custom_fields.'.$field['key'], $field['value']))>
                                    <label class="form-check-label" for="customField{{ $definition->id }}">Enabled</label>
                                </div>
                            @else
                                <input id="customField{{ $definition->id }}" type="{{ match($field['type']) { 'number' => 'number', 'date' => 'date', 'datetime' => 'datetime-local', 'email' => 'email', 'url' => 'url', default => 'text' } }}" name="custom_fields[{{ $field['key'] }}]" value="{{ old('custom_fields.'.$field['key'], $field['value']) }}" class="form-control @error('custom_fields.'.$field['key']) is-invalid @enderror">
                            @endif

                            @error('custom_fields.'.$field['key'])
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            @if($field['help_text'])
                                <div class="form-text">{{ $field['help_text'] }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Save Custom Fields</button>
            </div>
        </form>
    @endif

    {{-- Other settings cards can be added here later --}}
    <div class="accordion" id="clientSettingsAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="generalSettingsHeader">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#generalSettingsCollapse" aria-expanded="false" aria-controls="generalSettingsCollapse">
                    General Settings
                </button>
            </h2>
            <div id="generalSettingsCollapse" class="accordion-collapse collapse" aria-labelledby="generalSettingsHeader" data-bs-parent="#clientSettingsAccordion">
                <div class="accordion-body text-muted">
                    Additional client settings will be available here.
                </div>
            </div>
        </div>
    </div>
@endsection
