@extends('layouts.default_tech')

@php
    $isEditing = $vendor->exists;
    $isSupplierView = $role === 'suppliers';
    $pageTitle = $isEditing ? 'Edit ' . $vendor->name : 'Create ' . ($isSupplierView ? 'Supplier' : 'Vendor');
    $formAction = $isEditing
        ? route('tech.documentations.vendors.update', $vendor)
        : ($isSupplierView ? route('tech.documentations.suppliers.store') : route('tech.documentations.vendors.store'));
@endphp

@section('title', $pageTitle)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $pageTitle }}</h1>
        <x-buttons.back url="{{ $isEditing ? route('tech.documentations.vendors.show', $vendor) : route('tech.documentations.index', ['cat' => $role]) }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Fixed vendor and supplier master data form -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">{{ $isEditing ? 'Edit' : 'Create' }}</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ $formAction }}">
                @csrf
                @if($isEditing)
                    @method('PATCH')
                @endif

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Name</label>
                        <input id="name" name="name" type="text" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $vendor->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3">
                        <label for="vendor_code" class="form-label">Code</label>
                        <input id="vendor_code" name="vendor_code" type="text" class="form-control @error('vendor_code') is-invalid @enderror" value="{{ old('vendor_code', $vendor->vendor_code) }}">
                        @error('vendor_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3">
                        <label for="org_no" class="form-label">Org No.</label>
                        <input id="org_no" name="org_no" type="text" class="form-control @error('org_no') is-invalid @enderror" value="{{ old('org_no', $vendor->org_no) }}">
                        @error('org_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label for="url" class="form-label">Website</label>
                        <input id="url" name="url" type="text" class="form-control @error('url') is-invalid @enderror" value="{{ old('url', $vendor->url) }}" placeholder="https://">
                        @error('url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label for="email" class="form-label">Email</label>
                        <input id="email" name="email" type="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $vendor->email) }}">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-4">
                        <label for="phone" class="form-label">Phone</label>
                        <input id="phone" name="phone" type="text" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $vendor->phone) }}">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-3">
                        <label for="default_lead_time_days" class="form-label">Lead Time Days</label>
                        <input id="default_lead_time_days" name="default_lead_time_days" type="number" min="0" class="form-control @error('default_lead_time_days') is-invalid @enderror" value="{{ old('default_lead_time_days', $vendor->default_lead_time_days ?? 0) }}">
                        @error('default_lead_time_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-9">
                        <div class="form-label">Roles</div>
                        <div class="d-flex flex-wrap gap-3">
                            <input type="hidden" name="is_vendor" value="0">
                            <div class="form-check">
                                <input id="is_vendor" name="is_vendor" value="1" type="checkbox" class="form-check-input" @checked(old('is_vendor', $vendor->is_vendor))>
                                <label for="is_vendor" class="form-check-label">Vendor</label>
                            </div>

                            <input type="hidden" name="is_manufacturer" value="0">
                            <div class="form-check">
                                <input id="is_manufacturer" name="is_manufacturer" value="1" type="checkbox" class="form-check-input" @checked(old('is_manufacturer', $vendor->is_manufacturer))>
                                <label for="is_manufacturer" class="form-check-label">Manufacturer</label>
                            </div>

                            <input type="hidden" name="is_supplier" value="0">
                            <div class="form-check">
                                <input id="is_supplier" name="is_supplier" value="1" type="checkbox" class="form-check-input" @checked(old('is_supplier', $vendor->is_supplier))>
                                <label for="is_supplier" class="form-check-label">Supplier</label>
                            </div>

                            <input type="hidden" name="is_active" value="0">
                            <div class="form-check">
                                <input id="is_active" name="is_active" value="1" type="checkbox" class="form-check-input" @checked(old('is_active', $vendor->is_active))>
                                <label for="is_active" class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="terms" class="form-label">Terms</label>
                        <textarea id="terms" name="terms" rows="5" class="form-control @error('terms') is-invalid @enderror">{{ old('terms', $vendor->terms) }}</textarea>
                        @error('terms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label for="note" class="form-label">Notes</label>
                        <textarea id="note" name="note" rows="5" class="form-control @error('note') is-invalid @enderror">{{ old('note', $vendor->note) }}</textarea>
                        @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="{{ $isEditing ? route('tech.documentations.vendors.show', $vendor) : route('tech.documentations.index', ['cat' => $role]) }}" class="btn btn-sm btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />

    <hr class="my-3">

    <x-nav.side-bar :items="$sidebarMenuItems" title="Documentation categories" />
@endsection

@section('rightbar')
@endsection
