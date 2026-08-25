@extends('layouts.default_tech')

@section('title', 'Commercial Contract Settings')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-1">Commercial Contract Settings</h1>
        <div class="text-muted small">Operational contract configuration and related commercial policy surfaces.</div>
    </div>
@endsection

@section('content')
    <!-- Commercial contract settings hub -->
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <h2 class="h5 mb-0">Contract Operations</h2>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <a href="{{ route('tech.contracts.index') }}" class="btn btn-outline-primary w-100 h-100 text-start p-3">
                                <span class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                    Contracts
                                </span>
                                <span class="d-block small text-muted mt-1">Manage customer agreements, quote delivery, approvals, and contract lifecycle.</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="{{ route('tech.sla.index') }}" class="btn btn-outline-primary w-100 h-100 text-start p-3">
                                <span class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-stopwatch" aria-hidden="true"></i>
                                    SLA Policies
                                </span>
                                <span class="d-block small text-muted mt-1">Configure response and resolution commitments used by contracts and tickets.</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="{{ route('tech.legal.index') }}" class="btn btn-outline-primary w-100 h-100 text-start p-3">
                                <span class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                                    Legal Terms
                                </span>
                                <span class="d-block small text-muted mt-1">Maintain reusable terms used by services, packages, and customer contracts.</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="{{ route('tech.admin.settings.economy') }}" class="btn btn-outline-primary w-100 h-100 text-start p-3">
                                <span class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-receipt" aria-hidden="true"></i>
                                    Orders And Billing
                                </span>
                                <span class="d-block small text-muted mt-1">Review commercial order generation and billing-related settings.</span>
                            </a>
                        </div>
                        <div class="col-md-6">
                            <a href="{{ route('tech.admin.settings.cs.timebank-policy') }}" class="btn btn-outline-primary w-100 h-100 text-start p-3">
                                <span class="d-flex align-items-center gap-2 fw-semibold">
                                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                                    Timebank Policy
                                </span>
                                <span class="d-block small text-muted mt-1">Control quick Client timebank registration, notes, limits, and overuse behavior.</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <x-card.default title="How this works">
                <p class="small text-muted mb-2">
                    Contract behavior is currently configured through the concrete commercial records
                    that already drive production workflows.
                </p>
                <p class="small text-muted mb-0">
                    Timebank quick registration policy is implemented as a scoped Commercial setting.
                </p>
            </x-card.default>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="commercial" />
@endsection
