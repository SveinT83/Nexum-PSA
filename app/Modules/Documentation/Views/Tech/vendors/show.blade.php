@extends('layouts.default_tech')

@php
    $websiteUrl = $vendor->url && ! \Illuminate\Support\Str::startsWith($vendor->url, ['http://', 'https://'])
        ? 'https://' . $vendor->url
        : $vendor->url;
@endphp

@section('title', $vendor->name)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $vendor->name }}</h1>
        <x-buttons.back url="{{ route('tech.documentations.index', ['cat' => $vendor->is_supplier && ! $vendor->is_vendor && ! $vendor->is_manufacturer ? 'suppliers' : 'vendors']) }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Vendor and supplier profile -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <div>
                <h2 class="h6 mb-0">Profile</h2>
                <div class="small text-muted">{{ $vendor->vendor_code ?: 'No code' }}</div>
            </div>
            <x-buttons.editlink :url="route('tech.documentations.vendors.edit', $vendor)" class="mb-0">
                Edit
            </x-buttons.editlink>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Name</dt>
                        <dd class="col-sm-8">{{ $vendor->name }}</dd>

                        <dt class="col-sm-4">Code</dt>
                        <dd class="col-sm-8">{{ $vendor->vendor_code ?: '—' }}</dd>

                        <dt class="col-sm-4">Org No.</dt>
                        <dd class="col-sm-8">{{ $vendor->org_no ?: '—' }}</dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">
                            @if($vendor->is_active)
                                <span class="badge text-bg-success">Active</span>
                            @else
                                <span class="badge text-bg-secondary">Inactive</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Roles</dt>
                        <dd class="col-sm-8">
                            <div class="d-flex flex-wrap gap-1">
                                @if($vendor->is_vendor)
                                    <span class="badge text-bg-secondary">Vendor</span>
                                @endif
                                @if($vendor->is_manufacturer)
                                    <span class="badge text-bg-info">Manufacturer</span>
                                @endif
                                @if($vendor->is_supplier)
                                    <span class="badge text-bg-primary">Supplier</span>
                                @endif
                                @unless($vendor->is_vendor || $vendor->is_manufacturer || $vendor->is_supplier)
                                    <span class="text-muted">—</span>
                                @endunless
                            </div>
                        </dd>
                    </dl>
                </div>

                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Website</dt>
                        <dd class="col-sm-8">
                            @if($vendor->url)
                                <a href="{{ $websiteUrl }}" target="_blank" rel="noopener">{{ $vendor->url }}</a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4">Email</dt>
                        <dd class="col-sm-8">{{ $vendor->email ?: '—' }}</dd>

                        <dt class="col-sm-4">Phone</dt>
                        <dd class="col-sm-8">{{ $vendor->phone ?: '—' }}</dd>

                        <dt class="col-sm-4">Lead Time</dt>
                        <dd class="col-sm-8">{{ $vendor->default_lead_time_days }} days</dd>
                    </dl>
                </div>

                <div class="col-md-6">
                    <h3 class="h6">Terms</h3>
                    <div class="border rounded p-3 bg-light h-100">
                        {!! nl2br(e($vendor->terms ?: '—')) !!}
                    </div>
                </div>

                <div class="col-md-6">
                    <h3 class="h6">Notes</h3>
                    <div class="border rounded p-3 bg-light h-100">
                        {!! nl2br(e($vendor->note ?: '—')) !!}
                    </div>
                </div>
            </div>
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
