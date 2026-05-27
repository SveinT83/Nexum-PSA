@props(['client' => null, 'site' => null])

@php
    $query = \App\Models\Tech\Work\Assets\Asset::query();

    if ($site) {
        $query->where('site_id', $site->id);
    } elseif ($client) {
        $query->where('client_id', $client->id);
    }

    $assetSearch = request('asset_search');
    $assetSort = request('asset_sort', 'name');
    $assetDirection = request('asset_direction', 'asc') === 'desc' ? 'desc' : 'asc';
    $sortableColumns = ['name', 'type', 'site', 'owner', 'status'];

    if (! in_array($assetSort, $sortableColumns, true)) {
        $assetSort = 'name';
    }

    if (filled($assetSearch)) {
        $query->where(function ($query) use ($assetSearch): void {
            $query->where('name', 'like', "%{$assetSearch}%")
                ->orWhere('hostname', 'like', "%{$assetSearch}%")
                ->orWhere('type', 'like', "%{$assetSearch}%")
                ->orWhere('status', 'like', "%{$assetSearch}%")
                ->orWhereHas('site', fn ($query) => $query->where('name', 'like', "%{$assetSearch}%"))
                ->orWhereHas('user', fn ($query) => $query->where('name', 'like', "%{$assetSearch}%"));
        });
    }

    $query->with(['site', 'user']);

    if ($assetSort === 'site') {
        $query->leftJoin('client_sites', 'assets.site_id', '=', 'client_sites.id')
            ->select('assets.*')
            ->orderBy('client_sites.name', $assetDirection)
            ->orderBy('assets.name');
    } elseif ($assetSort === 'owner') {
        $query->leftJoin('client_users', 'assets.user_id', '=', 'client_users.id')
            ->select('assets.*')
            ->orderBy('client_users.name', $assetDirection)
            ->orderBy('assets.name');
    } else {
        $query->orderBy('assets.'.$assetSort, $assetDirection)
            ->orderBy('assets.name');
    }

    $assets = $query->get();

    $rmmIntegration = \App\Models\System\Integrations\Integration::where('type', 'rmm')->where('status', 'active')->first();
    $tacticalIntegration = \App\Models\System\Integrations\Integration::where('type', 'tactical_rmm')->where('status', 'active')->first();

    $clientWithRmm = $client ?: ($site?->client);
    $canSyncNable = $rmmIntegration && $clientWithRmm && $clientWithRmm->rmmLinks()->where('integration_id', $rmmIntegration->id)->exists();
    $canSyncTactical = $tacticalIntegration && $clientWithRmm && $clientWithRmm->rmmLinks()->where('integration_id', $tacticalIntegration->id)->exists();

    $missing = fn ($value) => filled($value) ? $value : '—';
    $sortLink = function (string $column) use ($assetSort, $assetDirection) {
        $nextDirection = $assetSort === $column && $assetDirection === 'asc' ? 'desc' : 'asc';

        return request()->fullUrlWithQuery([
            'asset_sort' => $column,
            'asset_direction' => $nextDirection,
        ]);
    };
    $sortIcon = function (string $column) use ($assetSort, $assetDirection) {
        if ($assetSort !== $column) {
            return 'bi-arrow-down-up';
        }

        return $assetDirection === 'asc' ? 'bi-sort-alpha-down' : 'bi-sort-alpha-up';
    };
@endphp

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-uppercase small opacity-75">
            <i class="bi bi-cpu me-2"></i> Assets
        </h6>
        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-light border">{{ $assets->count() }}</span>
            <a href="{{ route('tech.assets.create', ['client_id' => $client ? $client->id : ($site ? $site->client_id : null), 'site_id' => $site ? $site->id : null]) }}" class="btn btn-sm btn-primary mb-0">
                <i class="bi bi-plus-lg me-1"></i> New Asset
            </a>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Search and asset list actions -->
    <!-- ------------------------------------------------- -->
    <div class="card-body border-bottom">
        <div class="row align-items-end g-2">
            <form class="col-md" method="get">
                <div class="input-group input-group-sm">
                    <input type="text" name="asset_search" value="{{ $assetSearch }}" class="form-control" placeholder="Search name, hostname, type, status, site, or owner">
                    <input type="hidden" name="asset_sort" value="{{ $assetSort }}">
                    <input type="hidden" name="asset_direction" value="{{ $assetDirection }}">
                    <button class="btn btn-outline-secondary" type="submit">Search</button>
                </div>
            </form>
            @if($rmmIntegration)
                <div class="col-md-auto d-grid">
                    <button type="button"
                            class="btn btn-sm btn-outline-primary mb-0"
                            @if(!$canSyncNable) disabled title="Client not linked to N-able RMM" @endif
                            onclick="Livewire.dispatch('startTargetedSync', { params: { type: 'assets_from', client_id: {{ $client ? $client->id : ($site ? $site->client_id : 'null') }}, site_id: {{ $site ? $site->id : 'null' }} } })">
                        <i class="bi bi-arrow-repeat me-1"></i> Sync N-able
                    </button>
                </div>
            @endif
            @if($tacticalIntegration)
                <div class="col-md-auto d-grid">
                    <button type="button"
                            class="btn btn-sm btn-outline-info mb-0"
                            @if(!$canSyncTactical) disabled title="Client not linked to Tactical RMM" @endif
                            onclick="Livewire.dispatch('startTargetedTacticalSync', { params: { type: 'assets_from', client_id: {{ $client ? $client->id : ($site ? $site->client_id : 'null') }}, site_id: {{ $site ? $site->id : 'null' }} } })">
                        <i class="bi bi-arrow-repeat me-1"></i> Sync Tactical
                    </button>
                </div>
            @endif
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th class="ps-3">
                    <a href="{{ $sortLink('name') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                        Name <i class="bi {{ $sortIcon('name') }}"></i>
                    </a>
                </th>
                <th>
                    <a href="{{ $sortLink('type') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                        Type <i class="bi {{ $sortIcon('type') }}"></i>
                    </a>
                </th>
                @if(!$site)
                    <th>
                        <a href="{{ $sortLink('site') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Site <i class="bi {{ $sortIcon('site') }}"></i>
                        </a>
                    </th>
                @endif
                <th>
                    <a href="{{ $sortLink('owner') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                        Owner <i class="bi {{ $sortIcon('owner') }}"></i>
                    </a>
                </th>
                <th>
                    <a href="{{ $sortLink('status') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                        Status <i class="bi {{ $sortIcon('status') }}"></i>
                    </a>
                </th>
            </tr>
            </thead>
            <tbody>
            @forelse($assets as $asset)
                <tr class="cursor-pointer" data-href="{{ route('tech.assets.show', $asset->id) }}" onclick="window.location.href = this.dataset.href">
                    <td class="ps-3">
                        <a href="{{ route('tech.assets.show', $asset->id) }}" class="text-decoration-none fw-semibold" onclick="event.stopPropagation()">
                            {{ $asset->name }}
                        </a>
                        @if($asset->hostname)
                            <div class="small text-muted">{{ $asset->hostname }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border small">
                            {{ ucfirst($missing($asset->type)) }}
                        </span>
                    </td>
                    @if(!$site)
                        <td class="{{ blank($asset->site?->name) ? 'text-muted' : '' }}">
                            {{ $missing($asset->site?->name) }}
                        </td>
                    @endif
                    <td class="{{ blank($asset->user?->name) ? 'text-muted' : '' }}">
                        {{ $missing($asset->user?->name) }}
                    </td>
                    <td class="{{ blank($asset->status) ? 'text-muted' : '' }}">
                        {{ $missing($asset->status ? ucfirst($asset->status) : null) }}
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

    @if($assets->count() > 0)
        <div class="card-footer bg-white border-top-0 py-2 text-center">
            <a href="{{ $site ? route('tech.clients.assets.index', ['client' => $site->client_id, 'site_id' => $site->id]) : route('tech.clients.assets.index', $client->id) }}" class="small text-decoration-none fw-bold">
                View All Assets &rarr;
            </a>
        </div>
    @endif
</div>
