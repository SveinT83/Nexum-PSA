@props(['client' => null, 'site' => null])

@php
    $query = \App\Models\Tech\Work\Assets\Asset::query();

    if ($site) {
        $query->where('site_id', $site->id);
    } elseif ($client) {
        $query->where('client_id', $client->id);
    }

    $assets = $query->with(['site', 'user'])->latest()->get();

    // Check if RMM integration is active for the sync button
    $rmmIntegration = \App\Models\System\Integrations\Integration::where('type', 'rmm')->where('status', 'active')->first();

    $clientWithRmm = null;
    if ($client) {
        $clientWithRmm = $client;
    } elseif ($site && $site->client) {
        $clientWithRmm = $site->client;
    }

    $canSync = $rmmIntegration && $clientWithRmm && $clientWithRmm->rmm_id;
@endphp

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-uppercase small opacity-75">
            <i class="bi bi-cpu me-2"></i> Assets
        </h6>
        <div class="btn-group">
            <button type="button"
                    class="btn btn-sm btn-outline-primary"
                    @if(!$canSync) disabled title="Client not linked to RMM" @endif
                    onclick="Livewire.dispatch('startTargetedSync', { params: { type: 'assets_from', client_id: {{ $client ? $client->id : ($site ? $site->client_id : 'null') }}, site_id: {{ $site ? $site->id : 'null' }} } })">
                <i class="bi bi-arrow-repeat me-1"></i> Sync RMM
            </button>
            <a href="{{ route('tech.assets.create', ['client_id' => $client ? $client->id : ($site ? $site->client_id : null), 'site_id' => $site ? $site->id : null]) }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Create
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Type</th>
                        @if(!$site)
                            <th>Site</th>
                        @endif
                        <th>Owner</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($assets as $asset)
                        <tr>
                            <td class="ps-3">
                                <a href="{{ route('tech.assets.show', $asset->id) }}" class="text-decoration-none fw-semibold">
                                    {{ $asset->name }}
                                </a>
                                @if($asset->hostname)
                                    <div class="small text-muted">{{ $asset->hostname }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border small">
                                    {{ ucfirst($asset->type) }}
                                </span>
                            </td>
                            @if(!$site)
                                <td class="small">
                                    {{ $asset->site->name ?? '-' }}
                                </td>
                            @endif
                            <td class="small">
                                {{ $asset->user->name ?? '-' }}
                            </td>
                            <td class="text-end pe-3">
                                <div class="btn-group btn-group-sm">
                                    <a href="{{ route('tech.assets.show', $asset->id) }}" class="btn btn-link p-1 text-primary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="{{ route('tech.assets.edit', $asset->id) }}" class="btn btn-link p-1 text-secondary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $site ? 4 : 5 }}" class="text-center py-4 text-muted">
                                <p class="mb-0 small">No assets registered.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($assets->count() > 0)
        <div class="card-footer bg-white border-top-0 py-2 text-center">
            <a href="{{ $site ? route('tech.clients.assets.index', ['client' => $site->client_id, 'site_id' => $site->id]) : route('tech.clients.assets.index', $client->id) }}" class="small text-decoration-none fw-bold">
                View All Assets &rarr;
            </a>
        </div>
    @endif
</div>
