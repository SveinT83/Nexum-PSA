@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Edit Client</h1>
        <x-buttons.back url="{{ route('tech.clients.show', $client->id) }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <form action="{{ route('tech.clients.settings.update', $client->id) }}" method="POST">
        @csrf
        @method('PUT')

        <!-- ------------------------------------------------- -->
        <!-- Client details -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Client Details</h2>
                <span class="badge {{ $client->active ? 'text-bg-success' : 'text-bg-secondary' }}">
                    {{ $client->active ? 'Active' : 'Inactive' }}
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label for="client_number" class="form-label fw-semibold">Client number</label>
                        <input id="client_number" type="text" name="client_number" value="{{ old('client_number', $client->client_number) }}" class="form-control @error('client_number') is-invalid @enderror" placeholder="00000">
                        @error('client_number')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-5">
                        <label for="name" class="form-label fw-semibold">Name *</label>
                        <input id="name" type="text" name="name" value="{{ old('name', $client->name) }}" required class="form-control @error('name') is-invalid @enderror">
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-3">
                        <label for="org_no" class="form-label fw-semibold">Org No</label>
                        <input id="org_no" type="text" name="org_no" value="{{ old('org_no', $client->org_no) }}" class="form-control @error('org_no') is-invalid @enderror">
                        @error('org_no')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-2">
                        <label for="client_format_id" class="form-label fw-semibold">Format</label>
                        <select id="client_format_id" name="client_format_id" class="form-select @error('client_format_id') is-invalid @enderror">
                            <option value="">—</option>
                            @foreach(($clientFormats ?? []) as $format)
                                <option value="{{ $format->id }}" @selected((string) old('client_format_id', $client->client_format_id) === (string) $format->id)>{{ $format->code }}</option>
                            @endforeach
                        </select>
                        @error('client_format_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="billing_email" class="form-label fw-semibold">Billing Email</label>
                        <input id="billing_email" type="email" name="billing_email" value="{{ old('billing_email', $client->billing_email) }}" class="form-control @error('billing_email') is-invalid @enderror">
                        @error('billing_email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label for="website" class="form-label fw-semibold">Website</label>
                        <input id="website" type="text" name="website" value="{{ old('website', $client->website) }}" class="form-control @error('website') is-invalid @enderror">
                        @error('website')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold d-block">Status</label>
                        <input type="hidden" name="active" value="0">
                        <div class="form-check form-switch mt-2">
                            <input id="active" type="checkbox" name="active" value="1" class="form-check-input" @checked(old('active', $client->active))>
                            <label class="form-check-label" for="active">Client is active</label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label for="notes" class="form-label fw-semibold">Notes</label>
                        <textarea id="notes" name="notes" rows="4" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $client->notes) }}</textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- RMM mapping -->
        <!-- ------------------------------------------------- -->
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

                    <label for="rmm_external_id" class="form-label">Link to N-able RMM Client</label>
                    <select name="rmm_external_id" id="rmm_external_id" class="form-select" {{ isset($rmmError) ? 'disabled' : '' }}>
                        <option value="">-- {{ isset($rmmError) ? 'Error fetching clients' : 'No RMM link' }} --</option>
                        @foreach($rmmClients as $rmmClient)
                            <option value="{{ $rmmClient['clientid'] }}" @selected(($currentRmmId ?? '') == $rmmClient['clientid'])>
                                {{ $rmmClient['name'] }} ({{ $rmmClient['clientid'] }})
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Select the corresponding client in N-able RMM to enable synchronization.</div>
                </div>
            </div>
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Custom fields -->
        <!-- ------------------------------------------------- -->
        @if(($customFields ?? collect())->isNotEmpty())
            <div class="card mb-3">
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

                                @include('customfield::components.value-input', [
                                    'field' => $field,
                                    'inputName' => 'custom_fields['.$field['key'].']',
                                    'inputId' => 'customField'.$definition->id,
                                ])

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
            </div>
        @endif

        <div class="d-flex justify-content-end gap-2 mb-4">
            <a href="{{ route('tech.clients.show', $client) }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save Client</button>
        </div>
    </form>
@endsection
