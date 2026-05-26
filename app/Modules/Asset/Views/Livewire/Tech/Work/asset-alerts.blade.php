@php
    $accordionId = 'assetAlertsAccordion'.$asset->id;
    $collapseId = 'assetAlertsCollapse'.$asset->id;
    $hasActiveAlerts = $activeAlerts->isNotEmpty();
@endphp

<div id="{{ $accordionId }}" class="accordion mb-3">
    <div class="accordion-item border-warning">
        <h2 class="accordion-header">
            <button class="accordion-button py-2 {{ $hasActiveAlerts ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="{{ $hasActiveAlerts ? 'true' : 'false' }}" aria-controls="{{ $collapseId }}">
                <span class="d-flex align-items-center justify-content-between gap-2 w-100 me-2">
                    <span><i class="bi bi-exclamation-triangle-fill me-2 text-warning"></i>RMM Alerts</span>
                    <span class="badge {{ $hasActiveAlerts ? 'text-bg-warning' : 'text-bg-light border' }}">{{ $activeAlerts->count() }} active</span>
                </span>
            </button>
        </h2>
        <div id="{{ $collapseId }}" class="accordion-collapse collapse {{ $hasActiveAlerts ? 'show' : '' }}" data-bs-parent="#{{ $accordionId }}">
            <div class="accordion-body">
                <button wire:click="syncAlerts" wire:loading.attr="disabled" class="btn btn-sm btn-outline-dark w-100 mb-3">
                    <span wire:loading.remove wire:target="syncAlerts">
                        <i class="bi bi-arrow-repeat me-1"></i> Update Alerts
                    </span>
                    <span wire:loading wire:target="syncAlerts">
                        <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Syncing...
                    </span>
                </button>

                @if (session()->has('alert_sync_success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('alert_sync_success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                @if($activeAlerts->isEmpty())
                    <div class="text-center py-3 text-muted">
                        <i class="bi bi-check-circle fs-2 d-block mb-2"></i>
                        <p class="mb-0">No active alerts found.</p>
                    </div>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($activeAlerts as $alert)
                            <div class="list-group-item px-0 border-bottom">
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <h6 class="mb-1 text-danger fw-bold">{{ $alert->title }}</h6>
                                    <small class="text-muted">{{ $alert->last_seen_at->diffForHumans() }}</small>
                                </div>
                                <p class="mb-1 small text-dark" style="white-space: pre-wrap;">{{ $alert->message }}</p>
                                <div class="d-flex justify-content-between align-items-center mt-2">
                                    <span class="badge bg-danger">Active</span>
                                    <small class="text-muted">Source: {{ ucfirst($alert->integration_type) }}</small>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if($resolvedAlerts->isNotEmpty())
                    <hr>
                    <h6 class="text-muted small fw-bold mb-2">Recently Resolved</h6>
                    <div class="list-group list-group-flush small">
                        @foreach($resolvedAlerts as $alert)
                            <div class="list-group-item px-0 py-1 border-0">
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted"><del>{{ $alert->title }}</del></span>
                                    <span class="text-success small"><i class="bi bi-check-lg"></i> {{ $alert->resolved_at->format('M d, H:i') }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
