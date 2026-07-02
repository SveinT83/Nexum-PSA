@extends('layouts.default_tech')

@section('title', $relationship->exists ? 'Edit Nexum relationship' : 'New Nexum relationship')

@php
    $selectedCapabilities = old('capabilities', collect($relationship->capabilities ?? [])->filter()->keys()->all());
    $ticketPolicy = $relationship->ticket_policy ?? [];
    $documentationPolicy = $relationship->documentation_policy ?? [];
    $attachmentPolicy = $relationship->attachment_policy ?? [];
@endphp

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center w-100">
        <div>
            <h1 class="h4 mb-0">{{ $relationship->exists ? 'Edit Nexum relationship' : 'New Nexum relationship' }}</h1>
            <p class="text-muted mb-0">Configure the remote organization, credentials, capabilities, and routing policy.</p>
        </div>
        <a href="{{ $relationship->exists ? route('tech.admin.system.relationships.show', $relationship) : route('tech.admin.system.relationships.index') }}" class="btn btn-light">Back</a>
    </div>
@endsection

@section('content')
    <form method="POST" action="{{ $relationship->exists ? route('tech.admin.system.relationships.update', $relationship) : route('tech.admin.system.relationships.store') }}">
        @csrf
        @if($relationship->exists)
            @method('PATCH')
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Identity -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">Identity</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label" for="relationship-name">Name</label>
                        <input id="relationship-name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $relationship->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label" for="relationship-direction">Direction</label>
                        <select id="relationship-direction" name="direction" class="form-select @error('direction') is-invalid @enderror" required>
                            @foreach($directions as $value => $label)
                                <option value="{{ $value }}" @selected(old('direction', $relationship->direction) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('direction')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label" for="relationship-status">Status</label>
                        <select id="relationship-status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" @selected(old('status', $relationship->status) === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                        @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" for="relationship-client">Client when we are provider</label>
                        <select id="relationship-client" name="client_id" class="form-select @error('client_id') is-invalid @enderror">
                            <option value="">None</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}" @selected((string) old('client_id', $relationship->client_id) === (string) $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </select>
                        @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" for="relationship-vendor">Vendor when we use provider</label>
                        <select id="relationship-vendor" name="vendor_id" class="form-select @error('vendor_id') is-invalid @enderror">
                            <option value="">None</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected((string) old('vendor_id', $relationship->vendor_id) === (string) $vendor->id)>{{ $vendor->name }}</option>
                            @endforeach
                        </select>
                        @error('vendor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <input type="hidden" name="relationship_type" value="{{ old('relationship_type', $relationship->relationship_type ?: 'customer_provider') }}">
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Remote connection -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">Remote connection</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label" for="remote-base-url">Remote base URL</label>
                        <input id="remote-base-url" name="remote_base_url" class="form-control @error('remote_base_url') is-invalid @enderror" value="{{ old('remote_base_url', $relationship->remote_base_url) }}" placeholder="https://nexum.example.test">
                        @error('remote_base_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label" for="remote-instance-id">Remote instance ID</label>
                        <input id="remote-instance-id" name="remote_instance_id" class="form-control" value="{{ old('remote_instance_id', $relationship->remote_instance_id) }}">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label" for="remote-identifier">Organization identifier</label>
                        <input id="remote-identifier" name="remote_organization_identifier" class="form-control" value="{{ old('remote_organization_identifier', $relationship->remote_organization_identifier) }}">
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" for="remote-organization-name">Remote organization name</label>
                        <input id="remote-organization-name" name="remote_organization_name" class="form-control @error('remote_organization_name') is-invalid @enderror" value="{{ old('remote_organization_name', $relationship->remote_organization_name) }}">
                        @error('remote_organization_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label" for="service-areas">Service areas</label>
                        <textarea id="service-areas" name="service_areas" class="form-control" rows="2">{{ old('service_areas', $serviceAreasText) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Credentials -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">Credentials</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label" for="outbound-token">Outbound token</label>
                        <input id="outbound-token" type="password" name="outbound_token" class="form-control @error('outbound_token') is-invalid @enderror" autocomplete="new-password">
                        @error('outbound_token')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="inbound-token">Inbound token</label>
                        <input id="inbound-token" type="password" name="inbound_token" class="form-control @error('inbound_token') is-invalid @enderror" autocomplete="new-password">
                        @error('inbound_token')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="webhook-secret">Webhook signing secret</label>
                        <input id="webhook-secret" type="password" name="webhook_secret" class="form-control @error('webhook_secret') is-invalid @enderror" autocomplete="new-password">
                        @error('webhook_secret')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Capabilities and policy -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">Capabilities and policy</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="fw-semibold mb-2">Capabilities</div>
                        @foreach($capabilities as $capability)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="capabilities[]" value="{{ $capability }}" id="capability-{{ $capability }}" @checked(in_array($capability, $selectedCapabilities, true))>
                                <label class="form-check-label" for="capability-{{ $capability }}">{{ str_replace('_', ' ', ucfirst($capability)) }}</label>
                            </div>
                        @endforeach
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="ticket-queue">Ticket queue</label>
                        <select id="ticket-queue" name="ticket_queue_id" class="form-select">
                            <option value="">Use default ticket queue</option>
                            @foreach($queues as $queue)
                                <option value="{{ $queue->id }}" @selected((string) old('ticket_queue_id', $ticketPolicy['queue_id'] ?? null) === (string) $queue->id)>{{ $queue->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-check mt-2">
                            <input type="checkbox" class="form-check-input" name="ticket_auto_create_queue" value="1" id="ticket-auto-create" @checked(old('ticket_auto_create_queue', $ticketPolicy['auto_create_queue'] ?? false))>
                            <label class="form-check-label" for="ticket-auto-create">Create dedicated queue when needed</label>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="status-mapping">Status mapping</label>
                        <textarea id="status-mapping" name="status_mapping" class="form-control" rows="5" placeholder="local_slug=remote_slug">{{ old('status_mapping', $statusMappingText) }}</textarea>
                    </div>
                    <div class="col-lg-4">
                        <div class="form-check mt-1">
                            <input type="checkbox" class="form-check-input" name="documentation_two_way" value="1" id="documentation-two-way" @checked(old('documentation_two_way', $documentationPolicy['two_way'] ?? false))>
                            <label class="form-check-label" for="documentation-two-way">Allow two-way documentation review sync</label>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="attachment-max-mb">Attachment max MB</label>
                        <input id="attachment-max-mb" type="number" name="attachment_max_mb" min="1" max="100" class="form-control" value="{{ old('attachment_max_mb', $attachmentPolicy['max_mb'] ?? 10) }}">
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="attachment-types">Allowed attachment content types</label>
                        <textarea id="attachment-types" name="attachment_allowed_content_types" class="form-control" rows="2">{{ old('attachment_allowed_content_types', $attachmentTypesText) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ $relationship->exists ? route('tech.admin.system.relationships.show', $relationship) : route('tech.admin.system.relationships.index') }}" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $relationship->exists ? 'Save relationship' : 'Create relationship' }}</button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection
