@extends('layouts.default_tech')

@php
    $lifecycleClass = match ($carrier->lifecycle_state) {
        'active' => 'text-bg-success',
        'legacy' => 'text-bg-warning',
        default => 'text-bg-secondary',
    };
    $verificationClass = $carrier->verification_state === 'verified'
        ? 'text-bg-success'
        : ($carrier->verification_state === 'needs_review' ? 'text-bg-warning' : 'text-bg-secondary');
@endphp

@section('title', $carrier->name)

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $carrier->name }}</h1>
        <x-buttons.back url="{{ route('tech.documentations.shipping-carriers.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Carrier identity and operational classification -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h6 mb-0">Carrier Profile</h2>
                <span class="badge {{ $lifecycleClass }}">{{ $carrier->lifecycleLabel() }}</span>
            </div>
            @can('documentation.carrier_manage')
                <x-buttons.editlink :url="route('tech.documentations.shipping-carriers.edit', $carrier)" class="mb-0">
                    Edit
                </x-buttons.editlink>
            @endcan
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-6 col-xl-4">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Display Name</dt>
                        <dd class="col-sm-7">{{ $carrier->name }}</dd>
                        <dt class="col-sm-5">Stable Code</dt>
                        <dd class="col-sm-7"><code>{{ $carrier->code }}</code></dd>
                        <dt class="col-sm-5">Legal Name</dt>
                        <dd class="col-sm-7">{{ $carrier->legal_name ?: '—' }}</dd>
                        <dt class="col-sm-5">Vendor</dt>
                        <dd class="col-sm-7">{{ $carrier->vendor?->name ?: '—' }}</dd>
                    </dl>
                </div>
                <div class="col-md-6 col-xl-4">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Lifecycle</dt>
                        <dd class="col-sm-7"><span class="badge {{ $lifecycleClass }}">{{ $carrier->lifecycleLabel() }}</span></dd>
                        <dt class="col-sm-5">Sort Order</dt>
                        <dd class="col-sm-7">{{ $carrier->sort_order }}</dd>
                        <dt class="col-sm-5">Website</dt>
                        <dd class="col-sm-7"><a href="{{ $carrier->website_url }}" target="_blank" rel="noopener">Open website</a></dd>
                        <dt class="col-sm-5">Support</dt>
                        <dd class="col-sm-7">
                            @if($carrier->support_url)
                                <a href="{{ $carrier->support_url }}" target="_blank" rel="noopener">Open support</a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>
                    </dl>
                </div>
                <div class="col-md-12 col-xl-4">
                    <div class="fw-semibold mb-2">Service Tags</div>
                    <div class="d-flex flex-wrap gap-1">
                        @forelse(($carrier->service_tags ?? []) as $tag)
                            <span class="badge text-bg-light border">{{ $tag }}</span>
                        @empty
                            <span class="text-muted">—</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Safe tracking configuration -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <h2 class="h6 mb-0">Tracking Configuration</h2>
            <span class="badge {{ $verificationClass }}">{{ $carrier->verificationLabel() }}</span>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Method</dt>
                        <dd class="col-sm-7">{{ $carrier->trackingMethodLabel() }}</dd>
                        <dt class="col-sm-5">Visibility</dt>
                        <dd class="col-sm-7">{{ $carrier->linkVisibilityLabel() }}</dd>
                        <dt class="col-sm-5">Generic Page</dt>
                        <dd class="col-sm-7">
                            @if($carrier->tracking_page_url)
                                <a href="{{ $carrier->tracking_page_url }}" target="_blank" rel="noopener">Open tracking</a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>
                        <dt class="col-sm-5">Connector</dt>
                        <dd class="col-sm-7">{{ $carrier->connector_type ?: '—' }}</dd>
                    </dl>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <div class="fw-semibold mb-1">URL Template</div>
                        @if($carrier->tracking_url_template)
                            <code class="text-break">{{ $carrier->tracking_url_template }}</code>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </div>
                    <div>
                        <div class="fw-semibold mb-1">Allowed Hosts</div>
                        <div class="d-flex flex-column gap-1">
                            @forelse(($carrier->allowed_tracking_hosts ?? []) as $host)
                                <code>{{ $host }}</code>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Source, verification, and audit context -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Verification And Notes</h2>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">State</dt>
                        <dd class="col-sm-7"><span class="badge {{ $verificationClass }}">{{ $carrier->verificationLabel() }}</span></dd>
                        <dt class="col-sm-5">Verified Date</dt>
                        <dd class="col-sm-7">{{ $carrier->verified_at?->format('Y-m-d') ?: '—' }}</dd>
                        <dt class="col-sm-5">Official Source</dt>
                        <dd class="col-sm-7"><a href="{{ $carrier->source_url }}" target="_blank" rel="noopener">Open source</a></dd>
                    </dl>
                </div>
                <div class="col-md-6">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Created By</dt>
                        <dd class="col-sm-7">{{ $carrier->creator?->name ?: 'Seeder / system' }}</dd>
                        <dt class="col-sm-5">Updated By</dt>
                        <dd class="col-sm-7">{{ $carrier->updater?->name ?: 'Seeder / system' }}</dd>
                        <dt class="col-sm-5">Updated</dt>
                        <dd class="col-sm-7">{{ $carrier->updated_at?->format('Y-m-d H:i') ?: '—' }}</dd>
                    </dl>
                </div>
                <div class="col-12">
                    <div class="fw-semibold mb-2">Administrative Notes</div>
                    <div class="border rounded bg-light p-3">{!! nl2br(e($carrier->notes ?: '—')) !!}</div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />

    <hr class="my-3">

    <x-nav.side-bar :items="$sidebarMenuItems" title="Documentation" />
@endsection

@section('rightbar')
@endsection
