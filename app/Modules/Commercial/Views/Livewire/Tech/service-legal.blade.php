<div>
    @php
        $isCloudFactory = $service?->source === 'cloudfactory';
        $legalSync = data_get($service?->cloudFactoryOffer?->provider_payload, 'legal_sync', []);
    @endphp

    @if($isCloudFactory || $this->providerTerms->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="h6 mb-0">Provider terms</h3>
                    <div class="small text-muted">Synchronized from Cloud Factory. Provider documents are read-only.</div>
                </div>
                @if($isCloudFactory)
                    <span class="badge text-bg-{{ data_get($legalSync, 'status') === 'current' ? 'success' : 'secondary' }}">
                        {{ data_get($legalSync, 'status') === 'current' ? 'Current' : 'Not supplied by provider' }}
                    </span>
                @endif
            </div>
            <div class="list-group list-group-flush">
                @forelse($this->providerTerms as $term)
                    @php($version = $term->currentVersion)
                    <div class="list-group-item py-3">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div>
                                <div class="fw-semibold">{{ $version?->name ?? $term->name }}</div>
                                <div class="small text-muted">
                                    {{ $version?->issuer ?? $term->issuer ?? 'Provider' }}
                                    ? {{ ucfirst($version?->type ?? $term->type) }}
                                    ? Version {{ $version?->version_label ?: 'not stated' }}
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge text-bg-{{ $term->sync_status === 'current' ? 'success' : 'warning' }}">
                                    {{ $term->sync_status === 'current' ? 'Current' : 'Not returned in latest sync' }}
                                </span>
                                <div class="small text-muted mt-1">
                                    Checked {{ $term->last_checked_at?->diffForHumans() ?: 'pending' }}
                                </div>
                            </div>
                        </div>
                        @if($version?->source_url ?? $term->source_url)
                            <a class="small" href="{{ $version?->source_url ?? $term->source_url }}" target="_blank" rel="noopener">
                                Open provider document <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                            </a>
                        @endif
                    </div>
                @empty
                    <div class="p-3 text-muted">
                        Cloud Factory did not supply a product legal document in the latest catalogue payload.
                        Nexum will check again during the monthly catalogue sync.
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    <div class="row g-3">
        @if($enabled !== 'disabled')
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="h6 mb-0">Available Nexum terms</h3>
                        <div class="small text-muted">Select additional approved Nexum documents. Content is maintained in the legal library.</div>
                    </div>
                    <div class="list-group list-group-flush overflow-auto" style="max-height: 24rem;">
                        @forelse($this->allTerms as $term)
                            @php($selected = in_array((string) $term->id, $selectedTermIds, true))
                            <button type="button"
                                    wire:click="toggleTerm({{ $term->id }})"
                                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2 {{ $selected ? 'active' : '' }}">
                                <span class="text-start">
                                    <span class="fw-semibold">{{ $term->name }}</span>
                                    <span class="d-block small {{ $selected ? 'text-white-50' : 'text-muted' }}">
                                        {{ ucfirst($term->type) }} ? Version {{ $term->currentVersion?->version_label ?: '1' }}
                                    </span>
                                </span>
                                <i class="bi {{ $selected ? 'bi-check-circle-fill' : 'bi-plus-circle' }}" aria-hidden="true"></i>
                            </button>
                        @empty
                            <div class="p-3 text-muted">No Nexum terms are available in the legal library.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <div class="{{ $enabled === 'disabled' ? 'col-12' : 'col-lg-6' }}">
            <div class="card h-100">
                <div class="card-header">
                    <h3 class="h6 mb-0">Additional Nexum terms</h3>
                    <div class="small text-muted">These are added alongside any synchronized provider terms.</div>
                </div>
                <div class="list-group list-group-flush overflow-auto" style="max-height: 24rem;">
                    @forelse($this->selectedNexumTerms as $term)
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <div>
                                <div class="fw-semibold">{{ $term->name }}</div>
                                <div class="small text-muted">
                                    {{ ucfirst($term->type) }} ? Version {{ $term->currentVersion?->version_label ?: '1' }}
                                </div>
                            </div>
                            <input type="hidden" name="terms[]" value="{{ $term->id }}">
                            @if($enabled !== 'disabled')
                                <button type="button" wire:click="removeTerm({{ $term->id }})" class="btn btn-sm btn-outline-danger" aria-label="Remove {{ $term->name }}">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            @endif
                        </div>
                    @empty
                        <div class="p-3 text-muted">No additional Nexum terms selected.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @foreach($this->providerTerms as $term)
        <input type="hidden" name="terms[]" value="{{ $term->id }}">
    @endforeach
</div>
