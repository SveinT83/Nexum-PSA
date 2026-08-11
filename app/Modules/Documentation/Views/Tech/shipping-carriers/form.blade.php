@extends('layouts.default_tech')

@php
    $isEditing = $carrier->exists;
    $pageTitle = $isEditing ? 'Edit ' . $carrier->name : 'Create Shipping Carrier';
    $formAction = $isEditing
        ? route('tech.documentations.shipping-carriers.update', $carrier)
        : route('tech.documentations.shipping-carriers.store');
    $serviceTags = old('service_tags', $carrier->service_tags ?? []);
    $allowedHosts = old('allowed_tracking_hosts', $carrier->allowed_tracking_hosts ?? []);
    $serviceTagsText = is_array($serviceTags) ? implode(PHP_EOL, $serviceTags) : $serviceTags;
    $allowedHostsText = is_array($allowedHosts) ? implode(PHP_EOL, $allowedHosts) : $allowedHosts;
@endphp

@section('title', $pageTitle)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $pageTitle }}</h1>
        <x-buttons.back url="{{ $isEditing ? route('tech.documentations.shipping-carriers.show', $carrier) : route('tech.documentations.shipping-carriers.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <form method="POST" action="{{ $formAction }}">
        @csrf
        @if($isEditing)
            @method('PATCH')
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Carrier identity and lifecycle -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Carrier Profile</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Display Name</label>
                        <input id="name" name="name" type="text" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $carrier->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="code" class="form-label">Stable Code</label>
                        <input id="code" name="code" type="text" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $carrier->code) }}" placeholder="postnord" required>
                        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="sort_order" class="form-label">Sort Order</label>
                        <input id="sort_order" name="sort_order" type="number" min="0" max="10000" class="form-control @error('sort_order') is-invalid @enderror" value="{{ old('sort_order', $carrier->sort_order ?? 100) }}" required>
                        @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="legal_name" class="form-label">Legal Name</label>
                        <input id="legal_name" name="legal_name" type="text" class="form-control @error('legal_name') is-invalid @enderror" value="{{ old('legal_name', $carrier->legal_name) }}">
                        @error('legal_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="vendor_id" class="form-label">Canonical Vendor (optional)</label>
                        <select id="vendor_id" name="vendor_id" class="form-select @error('vendor_id') is-invalid @enderror">
                            <option value="">No vendor link</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected((string) old('vendor_id', $carrier->vendor_id) === (string) $vendor->id)>
                                    {{ $vendor->name }}{{ $vendor->vendor_code ? ' (' . $vendor->vendor_code . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('vendor_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="lifecycle_state" class="form-label">Lifecycle</label>
                        <select id="lifecycle_state" name="lifecycle_state" class="form-select @error('lifecycle_state') is-invalid @enderror" required>
                            @foreach($lifecycleOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('lifecycle_state', $carrier->lifecycle_state) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('lifecycle_state')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-8">
                        <label for="service_tags" class="form-label">Service Tags</label>
                        <input id="service_tags" name="service_tags" type="text" class="form-control @error('service_tags') is-invalid @enderror" value="{{ $serviceTagsText }}" placeholder="parcel, freight, domestic, international">
                        <div class="form-text">Separate tags with commas or spaces. Use lowercase letters, digits, dashes, or underscores.</div>
                        @error('service_tags')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @error('service_tags.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="website_url" class="form-label">Official Website</label>
                        <input id="website_url" name="website_url" type="url" class="form-control @error('website_url') is-invalid @enderror" value="{{ old('website_url', $carrier->website_url) }}" placeholder="https://" required>
                        @error('website_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="support_url" class="form-label">Support Page</label>
                        <input id="support_url" name="support_url" type="url" class="form-control @error('support_url') is-invalid @enderror" value="{{ old('support_url', $carrier->support_url) }}" placeholder="https://">
                        @error('support_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Tracking link configuration -->
        <!-- ------------------------------------------------- -->
        <div class="card mb-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Tracking Links</h2>
            </div>
            <div class="card-body">
                <div class="alert alert-info py-2 small">
                    Nexum only renders HTTPS tracking links on the allowed hosts below. Templates are browser links and are never fetched by the server.
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="tracking_method" class="form-label">Tracking Method</label>
                        <select id="tracking_method" name="tracking_method" class="form-select @error('tracking_method') is-invalid @enderror" required>
                            @foreach($trackingMethodOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('tracking_method', $carrier->tracking_method) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('tracking_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="link_visibility" class="form-label">Link Visibility</label>
                        <select id="link_visibility" name="link_visibility" class="form-select @error('link_visibility') is-invalid @enderror" required>
                            @foreach($linkVisibilityOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('link_visibility', $carrier->link_visibility) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('link_visibility')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="connector_type" class="form-label">Future Connector Type</label>
                        <input id="connector_type" name="connector_type" type="text" class="form-control @error('connector_type') is-invalid @enderror" value="{{ old('connector_type', $carrier->connector_type) }}" placeholder="bring_tracking">
                        <div class="form-text">Identifier only. Do not enter credentials or secrets.</div>
                        @error('connector_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="tracking_page_url" class="form-label">Generic Tracking Page</label>
                        <input id="tracking_page_url" name="tracking_page_url" type="url" class="form-control @error('tracking_page_url') is-invalid @enderror" value="{{ old('tracking_page_url', $carrier->tracking_page_url) }}" placeholder="https://carrier.example/track">
                        <div class="form-text">Used as the safe fallback when no direct or verified template link is available.</div>
                        @error('tracking_page_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="tracking_url_template" class="form-label">Tracking URL Template</label>
                        <input id="tracking_url_template" name="tracking_url_template" type="text" class="form-control font-monospace @error('tracking_url_template') is-invalid @enderror" value="{{ old('tracking_url_template', $carrier->tracking_url_template) }}" placeholder="https://carrier.example/track/{tracking_number}">
                        <div class="form-text">A template must contain exactly one <code>{tracking_number}</code> placeholder and is used only while the profile is verified.</div>
                        @error('tracking_url_template')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="allowed_tracking_hosts" class="form-label">Allowed Tracking Hosts</label>
                        <textarea id="allowed_tracking_hosts" name="allowed_tracking_hosts" rows="4" class="form-control font-monospace @error('allowed_tracking_hosts') is-invalid @enderror" placeholder="tracking.carrier.example&#10;carrier.example" required>{{ $allowedHostsText }}</textarea>
                        <div class="form-text">One hostname per line. Do not include a scheme, path, wildcard, or port.</div>
                        @error('allowed_tracking_hosts')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @error('allowed_tracking_hosts.*')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Source verification and administrative notes -->
        <!-- ------------------------------------------------- -->
        <div class="card">
            <div class="card-header">
                <h2 class="h6 mb-0">Verification</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="source_url" class="form-label">Official Source URL</label>
                        <input id="source_url" name="source_url" type="url" class="form-control @error('source_url') is-invalid @enderror" value="{{ old('source_url', $carrier->source_url) }}" placeholder="https://" required>
                        @error('source_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="verification_state" class="form-label">Verification State</label>
                        <select id="verification_state" name="verification_state" class="form-select @error('verification_state') is-invalid @enderror" required>
                            @foreach($verificationOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('verification_state', $carrier->verification_state) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('verification_state')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="verified_at" class="form-label">Verified Date</label>
                        <input id="verified_at" name="verified_at" type="date" class="form-control @error('verified_at') is-invalid @enderror" value="{{ old('verified_at', $carrier->verified_at?->format('Y-m-d')) }}">
                        @error('verified_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="notes" class="form-label">Administrative Notes</label>
                        <textarea id="notes" name="notes" rows="4" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $carrier->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="{{ $isEditing ? route('tech.documentations.shipping-carriers.show', $carrier) : route('tech.documentations.shipping-carriers.index') }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary">Save Carrier</button>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />

    <hr class="my-3">

    <x-nav.side-bar :items="$sidebarMenuItems" title="Documentation categories" />
@endsection

@section('rightbar')
@endsection
