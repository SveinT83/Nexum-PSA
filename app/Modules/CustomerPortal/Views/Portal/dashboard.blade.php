@extends('customerportal::layouts.portal')

@section('title', 'Customer Portal')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Dashboard -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Customer Portal</h1>
            <div class="text-muted small">{{ $context->contact->display_name }}</div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h6 mb-0">Portal scope</h2>
                    <span class="badge text-bg-light border">{{ $context->membership->roleLabel() }}</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="small text-muted">Client</div>
                            <div class="fw-semibold">{{ $context->client->name }}</div>
                            <div class="small text-muted">{{ $context->client->client_number ?: 'No client number' }}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="small text-muted">Site</div>
                            <div class="fw-semibold">{{ $context->site?->name ?: 'All sites' }}</div>
                            <div class="small text-muted">{{ $context->site?->city ?: 'Scope applies to the selected customer access.' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h6 mb-0">Tickets</h2>
                    @if(Route::has('customer-portal.tickets.create'))
                        <a href="{{ route('customer-portal.tickets.create') }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                            New ticket
                        </a>
                    @endif
                </div>
                <div class="card-body">
                    <p class="text-muted">Create a support ticket and follow ticket replies that have been shared with this portal scope.</p>
                    @if(Route::has('customer-portal.tickets.index'))
                        <a href="{{ route('customer-portal.tickets.index') }}" class="btn btn-outline-primary">
                            <i class="bi bi-ticket-detailed me-1" aria-hidden="true"></i>
                            Open tickets
                        </a>
                    @endif
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Documents and Knowledge</h2>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        @if(Route::has('customer-portal.documents.index'))
                            <div class="col-sm-6">
                                <a href="{{ route('customer-portal.documents.index') }}" class="btn btn-outline-primary w-100 text-start">
                                    <i class="bi bi-folder2-open me-1" aria-hidden="true"></i>
                                    Documents
                                </a>
                            </div>
                        @endif
                        @if(Route::has('customer-portal.knowledge.index'))
                            <div class="col-sm-6">
                                <a href="{{ route('customer-portal.knowledge.index') }}" class="btn btn-outline-primary w-100 text-start">
                                    <i class="bi bi-journal-text me-1" aria-hidden="true"></i>
                                    Knowledge
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Quotes, Contracts and Orders</h2>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        @if(Route::has('customer-portal.quotes.index'))
                            <div class="col-sm-4">
                                <a href="{{ route('customer-portal.quotes.index') }}" class="btn btn-outline-primary w-100 text-start">
                                    <i class="bi bi-file-earmark-check me-1" aria-hidden="true"></i>
                                    Quotes
                                </a>
                            </div>
                        @endif
                        @if(Route::has('customer-portal.contracts.index'))
                            <div class="col-sm-4">
                                <a href="{{ route('customer-portal.contracts.index') }}" class="btn btn-outline-primary w-100 text-start">
                                    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>
                                    Contracts
                                </a>
                            </div>
                        @endif
                        @if(Route::has('customer-portal.orders.index'))
                            <div class="col-sm-4">
                                <a href="{{ route('customer-portal.orders.index') }}" class="btn btn-outline-primary w-100 text-start">
                                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>
                                    Orders
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Memberships</h2>
                </div>
                <div class="list-group list-group-flush">
                    @foreach($memberships as $membership)
                        <div class="list-group-item">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <div>
                                    <div class="fw-semibold">{{ $membership->client?->name }}</div>
                                    <div class="small text-muted">{{ $membership->site?->name ?: 'All sites' }} &middot; {{ $membership->roleLabel() }}</div>
                                </div>

                                @if((int) $membership->id === (int) $context->membership->id)
                                    <span class="badge text-bg-primary">Active</span>
                                @else
                                    <form method="POST" action="{{ route('customer-portal.memberships.switch', $membership) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Switch</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection
