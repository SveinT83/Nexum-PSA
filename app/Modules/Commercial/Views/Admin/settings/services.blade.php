@extends('layouts.default_tech')

@section('title', 'Commercial Service Settings')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-1">Commercial Service Settings</h1>
        <div class="text-muted small">Service catalogue, pricing, packages, costs, and reusable commercial defaults.</div>
    </div>
@endsection

@section('content')
    <!-- Commercial service settings hub -->
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <h2 class="h5 mb-0">Service Catalogue</h2>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <a href="{{ route('tech.services.index') }}" class="btn btn-outline-primary w-100 h-100 text-start p-3">
                                <span class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-layers" aria-hidden="true"></i>
                                    Services
                                </span>
                                <span class="d-block small text-muted mt-1">Maintain the service catalogue used by contracts and packages.</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="{{ route('tech.packages.index') }}" class="btn btn-outline-primary w-100 h-100 text-start p-3">
                                <span class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-boxes" aria-hidden="true"></i>
                                    Packages
                                </span>
                                <span class="d-block small text-muted mt-1">Bundle services, legal terms, and pricing into reusable offers.</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="{{ route('tech.rates.index') }}" class="btn btn-outline-primary w-100 h-100 text-start p-3">
                                <span class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-cash-coin" aria-hidden="true"></i>
                                    Time Rates
                                </span>
                                <span class="d-block small text-muted mt-1">Manage hourly rates used by ticket time and commercial work.</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="{{ route('tech.costs.index') }}" class="btn btn-outline-primary w-100 h-100 text-start p-3">
                                <span class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-cart-check" aria-hidden="true"></i>
                                    Costs
                                </span>
                                <span class="d-block small text-muted mt-1">Maintain reusable costs, vendor lines, and billable commercial items.</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <x-card.default title="How this works">
                <p class="small text-muted mb-2">
                    Service defaults are currently managed on services, packages, rates, costs, and
                    taxonomy records directly.
                </p>
                <p class="small text-muted mb-0">
                    Dedicated global service policy settings should only be exposed when the matching
                    behavior exists and has tests.
                </p>
            </x-card.default>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="commercial" />
@endsection
