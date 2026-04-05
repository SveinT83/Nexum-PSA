{{--
    Client Show View

    This view displays the profile and summary information for a single client.
    When this page is loaded, the client is set as the "active client" in the session,
    affecting the context for sites, users, and documentation filters.
--}}
@extends('layouts.default_tech')

@section('pageHeader')
	<div class="d-flex justify-content-between align-items-center py-3">
		<h2 class="h4 mb-0">Client: {{ $client->name }}</h2>
		<div>
			<a href="{{ route('tech.clients.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
		</div>
	</div>
@endsection

@section('content')

    <div class="row">

        <!-- ------------------------------------------------- -->
        <!-- Client Summary -->
        <!-- ------------------------------------------------- -->
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header">Summary</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3">Name</dt><dd class="col-sm-9">{{ $client->name }}</dd>
                        <dt class="col-sm-3">Org No</dt><dd class="col-sm-9">{{ $client->org_no ?? '—' }}</dd>
                        <dt class="col-sm-3">Billing Email</dt><dd class="col-sm-9">{{ $client->billing_email ?? '—' }}</dd>
                        <dt class="col-sm-3">Status</dt><dd class="col-sm-9">@if($client->active)<span class="badge bg-success">Active</span>@else<span class="badge bg-secondary">Inactive</span>@endif</dd>
                        <dt class="col-sm-3">Notes</dt><dd class="col-sm-9">{{ $client->notes ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

    </div>
@endsection

@section('sidebar')
@endsection

@section('rightbar')
    {{--
        Risk Analysis Summary Widget
        This widget provides a high-level overview of the client's current risk status.
        It displays the aggregated risk score and highlights the top 3 most critical risks
        identified across all assessments.
    --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center bg-light">
            <span class="fw-bold text-uppercase small opacity-75">Risk Overview</span>
            @if($client->risk_score !== null)
                <span class="badge {{ $client->risk_score_badge_class }}">
                    Score: {{ $client->risk_score }}
                </span>
            @else
                <span class="badge text-bg-secondary opacity-50">N/A</span>
            @endif
        </div>
        <div class="card-body p-0">
            @php
                $topRisks = $client->top_risks;
            @endphp

            @if($topRisks->count() > 0)
                <div class="list-group list-group-flush">
                    @foreach($topRisks as $risk)
                        {{--
                            Individual Risk Item Link
                            Each item links directly to its detailed update/history page
                            to allow quick action on high-priority risks.
                        --}}
                        <a href="{{ route('tech.risk.items.show', $risk->id) }}" class="list-group-item list-group-item-action p-3">
                            <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                <h6 class="mb-0 text-truncate fw-bold" style="max-width: 150px;">{{ $risk->title }}</h6>
                                <span class="badge {{ $risk->score_badge_class }}">{{ $risk->score }}</span>
                            </div>
                            <small class="text-muted d-block text-truncate opacity-75">{{ $risk->description }}</small>
                            <div class="mt-2">
                                <span class="badge bg-light text-dark border small">Status: {{ ucfirst($risk->status) }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="p-4 text-center text-muted">
                    <i class="fas fa-shield-alt fa-2x mb-2 d-block opacity-25"></i>
                    <small>No risk assessments found for this client.</small>
                </div>
            @endif
        </div>
        @if($topRisks->count() > 0)
            <div class="card-footer bg-white border-top-0 py-2">
                <a href="{{ route('tech.risk.index', ['active_client_id' => $client->id]) }}" class="small text-decoration-none fw-bold">
                    View All Assessments &rarr;
                </a>
            </div>
        @endif
    </div>

    {{--
        Client Sites Widget
        This widget lists all sites belonging to the current client.
        Sites are physical locations or logical groupings where services are provided.
        Includes a direct action button to create new sites for this specific client.
    --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center bg-light">
            <h6 class="mb-0 fw-bold text-uppercase small opacity-75">Client Sites</h6>
            <span class="badge bg-primary rounded-pill">{{ $client->sites->count() }}</span>
        </div>
        <div class="card-body">
            @if($client->sites->count() > 0)
                <ul class="list-unstyled mb-3">
                    @foreach($client->sites as $site)
                        <li class="mb-2 pb-2 border-bottom border-light last-child-no-border">
                            <a href="{{ route('tech.clients.sites.show', $site->id) }}" class="text-decoration-none d-flex align-items-center">
                                <div class="bg-light rounded p-2 me-3">
                                    <i class="fas fa-building text-muted"></i>
                                </div>
                                <div>
                                    <span class="d-block text-dark fw-semibold small">{{ $site->name }}</span>
                                    <small class="text-muted small">{{ $site->city ?? 'No city' }}</small>
                                </div>
                                <i class="fas fa-chevron-right ms-auto small text-muted opacity-50"></i>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="text-center py-4 mb-3 border rounded border-dashed">
                    <p class="text-muted small mb-0">No sites registered for this client.</p>
                </div>
            @endif

            <div class="d-grid">
                <a href="{{ route('tech.clients.sites.create', $client->id) }}" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus-circle me-1"></i> Create New Site
                </a>
            </div>
        </div>
    </div>
@endsection

