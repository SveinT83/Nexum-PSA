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
