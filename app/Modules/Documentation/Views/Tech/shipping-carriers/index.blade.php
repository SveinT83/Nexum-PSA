@extends('layouts.default_tech')

@section('title', 'Shipping Carriers')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Shipping Carriers</h1>
        <x-buttons.back url="{{ route('tech.documentations.index', ['cat' => 'all']) }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Carrier search and lifecycle filters -->
    <!-- ------------------------------------------------- -->
    <form method="GET" action="{{ route('tech.documentations.shipping-carriers.index') }}" class="card mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label for="carrier_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
                    <input id="carrier_search" type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" placeholder="Name, code, legal name, or website">
                </div>
                <div class="col-md-3">
                    <label for="lifecycle_state" class="form-label text-muted small fw-bold text-uppercase">Lifecycle</label>
                    <select id="lifecycle_state" name="lifecycle_state" class="form-select form-select-sm">
                        <option value="">All states</option>
                        @foreach($lifecycleOptions as $value => $label)
                            <option value="{{ $value }}" @selected($lifecycle === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1 d-grid">
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button>
                </div>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- Fixed Documentation-owned carrier register -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h6 mb-0">Carrier profiles</h2>
                <span class="badge text-bg-light border">{{ $carriers->total() }}</span>
            </div>
            @can('documentation.carrier_manage')
                <x-buttons.addlink :url="route('tech.documentations.shipping-carriers.create')" class="mb-0">
                    New Carrier
                </x-buttons.addlink>
            @endcan
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Services</th>
                        <th>Tracking</th>
                        <th>Website</th>
                        <th>Verification</th>
                        <th>Lifecycle</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($carriers as $carrier)
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
                        <tr class="cursor-pointer" data-href="{{ route('tech.documentations.shipping-carriers.show', $carrier) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.documentations.shipping-carriers.show', $carrier) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $carrier->name }}
                                </a>
                                @if($carrier->legal_name && $carrier->legal_name !== $carrier->name)
                                    <div class="small text-muted">{{ $carrier->legal_name }}</div>
                                @endif
                            </td>
                            <td><code>{{ $carrier->code }}</code></td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @forelse(($carrier->service_tags ?? []) as $tag)
                                        <span class="badge text-bg-light border">{{ $tag }}</span>
                                    @empty
                                        <span class="text-muted">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td>{{ $carrier->trackingMethodLabel() }}</td>
                            <td>
                                <a href="{{ $carrier->website_url }}" target="_blank" rel="noopener" onclick="event.stopPropagation()">
                                    {{ parse_url($carrier->website_url, PHP_URL_HOST) ?: $carrier->website_url }}
                                </a>
                            </td>
                            <td><span class="badge {{ $verificationClass }}">{{ $carrier->verificationLabel() }}</span></td>
                            <td><span class="badge {{ $lifecycleClass }}">{{ $carrier->lifecycleLabel() }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No shipping carriers match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($carriers->hasPages())
            <div class="card-footer">
                {{ $carriers->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.knowledge-menu />

    <hr class="my-3">

    <x-nav.side-bar :items="$sidebarMenuItems" title="Documentation" />
@endsection

@section('rightbar')
@endsection
