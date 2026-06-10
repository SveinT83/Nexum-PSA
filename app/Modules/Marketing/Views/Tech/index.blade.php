@extends('layouts.default_tech')

@section('title', 'Marketing')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">Marketing</h1>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Marketing foundation status -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <span class="fw-semibold">Marketing Foundation</span>
                    <span class="badge text-bg-success">RFC approved</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Marketing owns mailing lists, campaign sequences, approvals, tracking, suppression, and sales follow-up signals.
                    </p>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <a href="{{ route('tech.marketing.lists.index') }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-people" aria-hidden="true"></i>
                            Mailing lists
                        </a>
                        <a href="{{ route('tech.marketing.campaigns.index') }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-megaphone" aria-hidden="true"></i>
                            Campaigns
                        </a>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted text-uppercase fw-semibold mb-1">Template source</div>
                                <div class="fw-semibold">Email templates</div>
                                <div class="small text-muted">Marketing campaigns will use the shared Email template system with branded HTML rendering.</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100">
                                <div class="small text-muted text-uppercase fw-semibold mb-1">Default sender</div>
                            <div class="fw-semibold">Email account scope: marketing</div>
                            <div class="small text-muted">Campaigns can override the sender account and otherwise use the default marketing account.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <span class="fw-semibold">Planned Capabilities</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Capability</th>
                                <th class="text-end">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($plannedCapabilities as $capability)
                                <tr>
                                    <td>{{ $capability }}</td>
                                    <td class="text-end"><span class="badge text-bg-light border">Planned slice</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">
                    <span class="fw-semibold">Build Order</span>
                </div>
                <div class="list-group list-group-flush">
                    <div class="list-group-item">
                        <div class="fw-semibold">1. Domain foundation</div>
                        <div class="small text-muted">Module, permissions, navigation, ADR, and documentation.</div>
                    </div>
                    <div class="list-group-item">
                        <div class="fw-semibold">2. Email defaults</div>
                        <div class="small text-muted">Add marketing sender scope to existing Email accounts.</div>
                    </div>
                    <div class="list-group-item">
                        <div class="fw-semibold">3. Branded templates</div>
                        <div class="small text-muted">HTML wrapper, preview, and seeded default marketing template.</div>
                    </div>
                    <div class="list-group-item">
                        <div class="fw-semibold">4. Lists and campaigns</div>
                        <div class="small text-muted">Recipients, consent policy, approvals, sending queue, and tracking foundation.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection
