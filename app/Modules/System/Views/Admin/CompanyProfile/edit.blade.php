@extends('layouts.default_tech')

@section('title', 'Company Profile')

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Central organization details used by the Nexum PSA shell. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Company Profile</h1>
        <x-buttons.back url="{{ route('tech.admin.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Company profile form -->
    <!-- The form stores app-wide organization settings in common_settings. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.system.company-profile.update') }}">
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h5 mb-0">Organization</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="company_name" class="form-label">Company name</label>
                                <input id="company_name" name="company_name" type="text" class="form-control" value="{{ old('company_name', $companyProfile['company_name']) }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="legal_name" class="form-label">Legal name</label>
                                <input id="legal_name" name="legal_name" type="text" class="form-control" value="{{ old('legal_name', $companyProfile['legal_name']) }}">
                            </div>
                            <div class="col-md-6">
                                <label for="organization_number" class="form-label">Organization number</label>
                                <input id="organization_number" name="organization_number" type="text" class="form-control" value="{{ old('organization_number', $companyProfile['organization_number']) }}">
                            </div>
                            <div class="col-md-6">
                                <label for="support_email" class="form-label">Support email</label>
                                <input id="support_email" name="support_email" type="email" class="form-control" value="{{ old('support_email', $companyProfile['support_email']) }}">
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone</label>
                                <input id="phone" name="phone" type="text" class="form-control" value="{{ old('phone', $companyProfile['phone']) }}">
                            </div>
                            <div class="col-md-6">
                                <label for="website" class="form-label">Website</label>
                                <input id="website" name="website" type="url" class="form-control" value="{{ old('website', $companyProfile['website']) }}" placeholder="https://example.com">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h2 class="h5 mb-0">Address</h2>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="address_line_1" class="form-label">Address line 1</label>
                                <input id="address_line_1" name="address_line_1" type="text" class="form-control" value="{{ old('address_line_1', $companyProfile['address_line_1']) }}">
                            </div>
                            <div class="col-md-6">
                                <label for="address_line_2" class="form-label">Address line 2</label>
                                <input id="address_line_2" name="address_line_2" type="text" class="form-control" value="{{ old('address_line_2', $companyProfile['address_line_2']) }}">
                            </div>
                            <div class="col-md-4">
                                <label for="postal_code" class="form-label">Postal code</label>
                                <input id="postal_code" name="postal_code" type="text" class="form-control" value="{{ old('postal_code', $companyProfile['postal_code']) }}">
                            </div>
                            <div class="col-md-4">
                                <label for="city" class="form-label">City</label>
                                <input id="city" name="city" type="text" class="form-control" value="{{ old('city', $companyProfile['city']) }}">
                            </div>
                            <div class="col-md-4">
                                <label for="country" class="form-label">Country</label>
                                <input id="country" name="country" type="text" class="form-control" value="{{ old('country', $companyProfile['country']) }}">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card">
                    <div class="card-header">
                        <h2 class="h5 mb-0">Company Identity</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row small mb-0">
                            <dt class="col-sm-5">Display name</dt>
                            <dd class="col-sm-7">{{ $companyProfile['company_name'] }}</dd>
                            <dt class="col-sm-5">Legal name</dt>
                            <dd class="col-sm-7">{{ $companyProfile['legal_name'] ?? 'Not set' }}</dd>
                            <dt class="col-sm-5">Support email</dt>
                            <dd class="col-sm-7">{{ $companyProfile['support_email'] ?? 'Not set' }}</dd>
                            <dt class="col-sm-5">Website</dt>
                            <dd class="col-sm-7">{{ $companyProfile['website'] ?? 'Not set' }}</dd>
                        </dl>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <x-buttons.back url="{{ route('tech.admin.index') }}">Cancel</x-buttons.back>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1" aria-hidden="true"></i>
                        Save profile
                    </button>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection

@section('rightbar')
    <x-card.default title="Branding">
        <p class="small text-muted mb-0">
            Logo, colors, and theme surfaces are managed from the dedicated Branding page.
        </p>
        <a href="{{ route('tech.admin.system.branding.edit') }}" class="btn btn-outline-primary btn-sm mt-3">Open branding</a>
    </x-card.default>
@endsection
