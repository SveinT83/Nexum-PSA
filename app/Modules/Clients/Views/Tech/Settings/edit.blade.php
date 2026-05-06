@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <h2 class="h4 mb-0">Client Settings: {{ $client->name }}</h2>
        <div>
            <a href="{{ route('tech.clients.show', $client->id) }}" class="btn btn-sm btn-outline-secondary">Back to Client</a>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            {{-- N-able RMM Link Card --}}
            @if($rmmIntegration && $rmmIntegration->status === 'active')
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">N-able RMM Integration</h5>
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
            <div class="card">
                <div class="card-header">General Settings</div>
                <div class="card-body">
                    <p class="text-muted">Additional client settings will be available here.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
