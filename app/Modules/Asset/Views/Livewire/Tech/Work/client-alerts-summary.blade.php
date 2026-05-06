<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <h5 class="mb-0 small fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Alerts Summary</h5>
        <button wire:click="syncAlerts" wire:loading.attr="disabled" class="btn btn-sm btn-outline-dark">
            <span wire:loading.remove wire:target="syncAlerts">
                <i class="bi bi-arrow-repeat"></i>
            </span>
            <span wire:loading wire:target="syncAlerts">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            </span>
        </button>
    </div>
    <div class="card-body py-3">
        @if (session()->has('alert_sync_success'))
            <div class="alert alert-success py-1 px-2 small mb-2">
                {{ session('alert_sync_success') }}
            </div>
        @endif

        <div class="row text-center g-0">
            <div class="col-6 border-end">
                <div class="display-6 fw-bold {{ $activeCount > 0 ? 'text-danger' : 'text-success' }}">{{ $activeCount }}</div>
                <div class="small text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Active</div>
            </div>
            <div class="col-6">
                <div class="display-6 fw-bold text-success">{{ $resolvedCount }}</div>
                <div class="small text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Resolved (24h)</div>
            </div>
        </div>

        @if($activeCount > 0)
            <div class="mt-3 d-grid">
                <a href="{{ $client ? route('tech.clients.assets.index', ['client' => $client->id, 'has_alerts' => 1]) : route('tech.assets.index', ['has_alerts' => 1]) }}" class="btn btn-sm btn-outline-danger">
                    View Assets with Alerts
                </a>
            </div>
        @endif
    </div>
</div>
