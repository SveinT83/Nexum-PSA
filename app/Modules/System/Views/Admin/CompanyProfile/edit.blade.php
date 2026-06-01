@extends('layouts.default_tech')

@section('title', 'Company Profile')

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Central branding and organization details used by the Nexum PSA shell. -->
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
    <!-- The form stores app-wide settings in common_settings and updates the live header branding. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.system.company-profile.update') }}" enctype="multipart/form-data">
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
                        <h2 class="h5 mb-0">Branding</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="logo" class="form-label">Header logo</label>
                            @if($companyProfile['logo_url'])
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <img src="{{ $companyProfile['logo_url'] }}" alt="{{ $companyProfile['company_name'] }} logo" class="company-profile-logo-preview">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="remove_logo" name="remove_logo" value="1">
                                        <label class="form-check-label" for="remove_logo">Remove current logo</label>
                                    </div>
                                </div>
                            @endif
                            <input id="logo" name="logo" type="file" class="form-control" accept="image/*">
                            <div class="form-text">PNG, JPG, GIF, or WebP up to 2 MB.</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="primary_color" class="form-label">Primary</label>
                                <input id="primary_color" name="primary_color" type="color" class="form-control form-control-color w-100" value="{{ old('primary_color', $companyProfile['primary_color']) }}">
                            </div>
                            <div class="col-md-4">
                                <label for="secondary_color" class="form-label">Secondary</label>
                                <input id="secondary_color" name="secondary_color" type="color" class="form-control form-control-color w-100" value="{{ old('secondary_color', $companyProfile['secondary_color']) }}">
                            </div>
                            <div class="col-md-4">
                                <label for="accent_color" class="form-label">Accent</label>
                                <input id="accent_color" name="accent_color" type="color" class="form-control form-control-color w-100" value="{{ old('accent_color', $companyProfile['accent_color']) }}">
                            </div>
                        </div>

                        <div class="company-brand-preview mt-4 p-3 rounded border">
                            <div class="d-flex align-items-center gap-2 mb-3">
                                @if($companyProfile['logo_url'])
                                    <img src="{{ $companyProfile['logo_url'] }}" alt="" class="company-brand-preview-logo">
                                @else
                                    <span class="company-brand-preview-mark">
                                        <i class="bi bi-buildings" aria-hidden="true"></i>
                                    </span>
                                @endif
                                <strong>{{ old('company_name', $companyProfile['company_name']) }}</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <span class="badge" style="background: {{ old('primary_color', $companyProfile['primary_color']) }};">Primary</span>
                                <span class="badge" style="background: {{ old('secondary_color', $companyProfile['secondary_color']) }};">Secondary</span>
                                <span class="badge text-dark" style="background: {{ old('accent_color', $companyProfile['accent_color']) }};">Accent</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <x-buttons.back url="{{ route('tech.admin.index') }}">Cancel</x-buttons.back>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1" aria-hidden="true"></i>
                        Save branding
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
    <x-card.default title="Branding behavior">
        <p class="small text-muted mb-0">
            The header uses this logo, company name, and color palette. Empty fields fall back to safe Nexum PSA defaults.
        </p>
    </x-card.default>
@endsection

@section('scripts')
    <style>
        .company-profile-logo-preview {
            max-height: 3rem;
            max-width: 12rem;
            object-fit: contain;
        }

        .company-brand-preview {
            background: var(--bs-tertiary-bg);
        }

        .company-brand-preview-logo {
            max-height: 2rem;
            max-width: 10rem;
            object-fit: contain;
        }

        .company-brand-preview-mark {
            width: 2rem;
            height: 2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--bs-border-color);
            border-radius: .5rem;
            color: var(--nexum-brand-primary, var(--bs-primary));
            background: var(--bs-body-bg);
        }
    </style>
@endsection
