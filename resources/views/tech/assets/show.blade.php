@extends('layouts.default_tech')

@section('title', 'Asset: ' . $asset->name)

{{--
    Asset Detail View
    -----------------
    This view displays comprehensive information about a specific asset,
    including technical specifications, ownership, and network details.
--}}

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.assets.index') }}">Assets</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $asset->name }}</li>
        </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ $asset->name }}</h1>
        <div class="btn-group mb-3">
            <x-buttons.back :url="route('tech.assets.index')" class="btn btn-sm btn-outline-secondary bi bi-arrow-left">Back to Assets</x-buttons.back>
            @php
                $rmmIntegration = \App\Models\System\Integrations\Integration::where('type', 'rmm')->where('status', 'active')->first();
                $canSync = $rmmIntegration && $asset->client && $asset->client->rmm_id;
            @endphp
            <button type="button"
                    class="btn btn-sm btn-outline-primary"
                    @if(!$canSync) disabled title="Client not linked to RMM" @endif
                    onclick="Livewire.dispatch('startTargetedSync', { params: { type: 'assets_from', client_id: {{ $asset->client_id }}, site_id: {{ $asset->site_id ?: 'null' }} } })">
                <i class="bi bi-arrow-repeat me-1"></i> Sync RMM
            </button>
            <x-buttons.editlink :url="route('tech.assets.edit', $asset->id)" class="btn btn-sm btn-outline-secondary bi bi-pencil">Edit</x-buttons.editlink>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">

            {{-- SECTION: Primary Asset Details --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Asset Details</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-sm-3 fw-bold">Client:</div>
                        <div class="col-sm-9">
                            <a href="{{ route('tech.clients.show', $asset->client_id) }}">
                                {{ $asset->client->name }}
                            </a>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 fw-bold">Site:</div>
                        <div class="col-sm-9">{{ $asset->site->name ?? '-' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 fw-bold">User / Owner:</div>
                        <div class="col-sm-9">{{ $asset->user->name ?? '-' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 fw-bold">Type:</div>
                        <div class="col-sm-9">{{ ucfirst($asset->type) }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 fw-bold">Vendor / Model:</div>
                        <div class="col-sm-9">
                            @if($asset->vendorRelation)
                                {{ $asset->vendorRelation->name }}
                            @else
                                {{ $asset->vendor ?? 'Unknown' }}
                            @endif
                             / {{ $asset->model ?? 'Unknown' }}
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 fw-bold">Serial Number:</div>
                        <div class="col-sm-9">{{ $asset->serial_number ?? 'N/A' }}</div>
                    </div>
                    <hr>
                    <div class="row mb-3">
                        <div class="col-sm-3 fw-bold">Hostname:</div>
                        <div class="col-sm-9">{{ $asset->hostname ?? 'N/A' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 fw-bold">IP Address:</div>
                        <div class="col-sm-9">
                            {{ $asset->ip_address ?? 'N/A' }}
                            @if($asset->ip_type)
                                <span class="badge bg-light text-dark border ms-1">{{ strtoupper($asset->ip_type) }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-sm-3 fw-bold">MAC Address:</div>
                        <div class="col-sm-9">{{ $asset->mac_address ?? 'N/A' }}</div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Related Tickets</h5>
                </div>
                {{-- SECTION: Related Tickets (Placeholder) --}}
                <div class="card-body py-4 text-center text-muted">
                    <p class="mb-0">No related tickets found. (Feature coming soon)</p>
                </div>
            </div>

            {{-- SECTION: System Metadata --}}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Raw Metadata</h5>
                </div>
                <div class="card-body">
                    @if($asset->metadata)
                        <pre class="bg-light p-3 rounded"><code>{{ json_encode($asset->metadata, JSON_PRETTY_PRINT) }}</code></pre>
                    @else
                        <p class="text-muted mb-0">No metadata available for this asset.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')
        {{-- SECTION: Status & Lifecycle Sidebar --}}
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Status & Lifecycle</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label d-block fw-bold">Current Status</label>
                    <span class="badge bg-light text-dark border fs-6">
                        N/A
                    </span>
                    <div class="small text-muted mt-1">(RMM integration pending)</div>
                </div>
                <div class="mb-3">
                    <label class="form-label d-block fw-bold">Source</label>
                    <span class="badge bg-light text-dark border">
                        {{ ucfirst($asset->source) }}
                    </span>
                </div>
                <div class="mb-3">
                    <label class="form-label d-block fw-bold">Managed</label>
                    @if($asset->is_managed)
                        <span class="text-success"><i class="bi bi-check-circle-fill"></i> Managed by RMM</span>
                    @else
                        <span class="text-muted"><i class="bi bi-circle"></i> Unmanaged / Manual</span>
                    @endif
                </div>
                <hr>
                <div class="small text-muted">
                    <div><strong>Last seen:</strong> {{ $asset->last_seen_at ? $asset->last_seen_at->format('Y-m-d H:i') : 'Never' }}</div>
                    <div><strong>Created:</strong> {{ $asset->created_at->format('Y-m-d H:i') }}</div>
                    <div><strong>Updated:</strong> {{ $asset->updated_at->format('Y-m-d H:i') }}</div>
                </div>
            </div>
        </div>

    <!-- ======================================================================
         DOCUMENTATION CARD
         - Provides a summary of the module and a link to the full documentation
         ====================================================================== -->
    <div class="card border-info mb-4">
        <div class="card-header d-flex justify-content-between align-items-center bg-info text-white">
            <h5 class="mb-0">Documentation</h5>
            <i class="bi bi-info-circle"></i>
        </div>
        <div class="card-body">
            <p class="small text-muted">
                Detailed user manual and technical documentation for the Asset Management module.
            </p>
            <div class="d-grid gap-2">
                <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#docModal">
                    <i class="bi bi-book me-1"></i> View Full Documentation
                </button>
            </div>
        </div>
    </div>

    <!-- ======================================================================
         DOCUMENTATION MODAL
         - Renders the assets.md documentation file within the UI
         ====================================================================== -->
    <div class="modal fade" id="docModal" tabindex="-1" aria-labelledby="docModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="docModalLabel">Asset Management Documentation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="markdown-body">
                        @php
                            $docPath = resource_path('views/tech/assets/assets.md');
                            if (file_exists($docPath)) {
                                if (class_exists('\Parsedown')) {
                                    $parsedown = new \Parsedown();
                                    echo $parsedown->text(file_get_contents($docPath));
                                } else {
                                    echo '<div class="alert alert-warning small">Markdown parser not found. Displaying raw documentation:</div>';
                                    echo '<pre style="white-space: pre-wrap; font-size: 0.85rem;">' . e(file_get_contents($docPath)) . '</pre>';
                                }
                            } else {
                                echo '<div class="alert alert-danger">Documentation file not found.</div>';
                            }
                        @endphp
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <a href="{{ route('tech.assets.docs') }}" class="btn btn-primary" target="_blank">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Open in New Tab
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
