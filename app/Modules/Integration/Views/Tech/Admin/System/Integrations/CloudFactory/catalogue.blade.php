@extends('layouts.default_tech')

@section('title', 'Cloud Factory Catalogue')

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
            <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.cloudfactory.index') }}">Cloud Factory</a></li>
            <li class="breadcrumb-item active" aria-current="page">Catalogue</li>
        </ol>
    </nav>
    <h1 class="h3 mb-0">Cloud Factory Catalogue</h1>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

@section('content')
    @php
        $sortQuery = collect($filters)->reject(fn ($value) => $value === '' || $value === null)->all();
        $recurrenceSortDirection = ($filters['sort'] ?? '') === 'recurrence_term' && ($filters['direction'] ?? '') === 'asc' ? 'desc' : 'asc';
        $billingSortDirection = ($filters['sort'] ?? '') === 'billing_term' && ($filters['direction'] ?? '') === 'asc' ? 'desc' : 'asc';
    @endphp
    <!-- Catalogue search, provider-term, and resale filters -->
    <form method="GET" action="{{ route('tech.admin.system.integrations.cloudfactory.catalogue') }}" class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-xl-4 col-md-6">
                    <label for="catalogue_q" class="form-label">Search</label>
                    <input id="catalogue_q" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"
                           placeholder="Product, SKU, or Vendor">
                </div>
                <div class="col-xl-2 col-md-6">
                    <label for="catalogue_vendor" class="form-label">Vendor</label>
                    <select id="catalogue_vendor" name="vendor" class="form-select">
                        <option value="">All Vendors</option>
                        <option value="unmapped" @selected(($filters['vendor'] ?? '') === 'unmapped')>Unmapped</option>
                        @foreach($vendors as $vendor)
                            <option value="{{ $vendor->id }}" @selected((string) ($filters['vendor'] ?? '') === (string) $vendor->id)>
                                {{ $vendor->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="catalogue_state" class="form-label">Resale state</label>
                    <select id="catalogue_state" name="state" class="form-select">
                        <option value="">Everything</option>
                        <option value="enabled" @selected(($filters['state'] ?? '') === 'enabled')>For sale</option>
                        <option value="excluded" @selected(($filters['state'] ?? '') === 'excluded')>Excluded</option>
                        <option value="subscribed" @selected(($filters['state'] ?? '') === 'subscribed')>Active subscriptions</option>
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="catalogue_recurrence_term" class="form-label">Commitment term</label>
                    <select id="catalogue_recurrence_term" name="recurrence_term" class="form-select">
                        <option value="">Any commitment</option>
                        @foreach($recurrenceTerms as $term)
                            <option value="{{ $term['value'] }}" @selected(($filters['recurrence_term'] ?? '') === $term['value'])>
                                {{ $term['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-xl-2 col-md-4">
                    <label for="catalogue_billing_term" class="form-label">Billing term</label>
                    <select id="catalogue_billing_term" name="billing_term" class="form-select">
                        <option value="">Any billing term</option>
                        @foreach($billingTerms as $term)
                            <option value="{{ $term['value'] }}" @selected(($filters['billing_term'] ?? '') === $term['value'])>
                                {{ $term['label'] }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <button class="btn btn-primary px-4">Apply</button>
                    @if(collect($filters)->contains(fn ($value) => $value !== '' && $value !== null))
                        <a href="{{ route('tech.admin.system.integrations.cloudfactory.catalogue') }}"
                           class="btn btn-outline-secondary" aria-label="Clear filters" title="Clear filters">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    <div class="alert alert-info py-2">
        <div>Each product is linked separately to a canonical Vendor in Nexum.</div>
        <div>Cost is the Cloud Factory purchase price and MSRP is the manufacturer suggested retail price.</div>
        <div class="mt-1">
            <strong>Catalogue staging:</strong> An offer appears in the ordinary Services list only after it is marked
            For sale or retained because an active subscription already uses it.
        </div>
    </div>

    <!-- Cloud Factory category to Vendor mappings -->
    <div class="card mb-3">
        <div class="card-header p-0">
            <div class="d-flex align-items-center">
                <h2 class="h5 mb-0 flex-grow-1">
                    <button type="button"
                            class="btn btn-link text-body text-decoration-none w-100 d-flex justify-content-between align-items-center px-3 py-2 collapsed"
                            data-bs-toggle="collapse"
                            data-bs-target="#cloudfactory-vendor-mappings"
                            aria-expanded="false"
                            aria-controls="cloudfactory-vendor-mappings">
                        <span>Vendor mappings</span>
                        <span class="d-flex align-items-center gap-2">
                            <span class="badge text-bg-success">{{ $vendorMappingCounts['mapped'] ?? 0 }} mapped</span>
                            @if(($vendorMappingCounts['unmapped'] ?? 0) > 0)
                                <span class="badge text-bg-warning">{{ $vendorMappingCounts['unmapped'] }} need mapping</span>
                            @endif
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </span>
                    </button>
                </h2>
                <a href="{{ route('tech.documentations.vendors.create') }}"
                   class="btn btn-sm btn-outline-secondary me-2 text-nowrap">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add Vendor
                </a>
            </div>
        </div>
        <div id="cloudfactory-vendor-mappings" class="collapse">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Cloud Factory category</th>
                            <th class="text-end">Products</th>
                            <th>Vendor</th>
                            <th>Mapping</th>
                            <th style="min-width: 300px">Change Vendor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($vendorLinks as $vendorLink)
                            @php
                                $automaticMatch = $vendorLink->vendor_id && $vendorLink->match_method !== 'manual';
                                $mappingLabel = $vendorLink->match_method === 'manual'
                                    ? 'Manual'
                                    : ($automaticMatch ? 'Automatic' : 'Needs mapping');
                                $mappingStyle = $vendorLink->match_method === 'manual'
                                    ? 'primary'
                                    : ($automaticMatch ? 'success' : 'warning');
                            @endphp
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $vendorLink->external_name }}</div>
                                    <div class="small text-muted">
                                        {{ $vendorLink->external_product_type ?: 'No product type' }}
                                        &middot; {{ $vendorLink->external_category_id }}
                                    </div>
                                </td>
                                <td class="text-end">
                                    {{ number_format((int) ($vendorProductCounts[$vendorLink->external_category_id] ?? 0), 0, ',', '.') }}
                                </td>
                                <td>
                                    @if($vendorLink->vendor)
                                        <a href="{{ route('tech.documentations.vendors.show', $vendorLink->vendor) }}">
                                            {{ $vendorLink->vendor->name }}
                                        </a>
                                    @else
                                        <span class="text-muted">Not mapped</span>
                                    @endif
                                </td>
                                <td><span class="badge text-bg-{{ $mappingStyle }}">{{ $mappingLabel }}</span></td>
                                <td>
                                    <form action="{{ route('tech.admin.system.integrations.cloudfactory.catalogue.vendors.update', $vendorLink) }}"
                                          method="POST" class="d-flex gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <select name="vendor_id" class="form-select form-select-sm" required
                                                aria-label="Vendor for {{ $vendorLink->external_name }}">
                                            <option value="">Select Vendor</option>
                                            @foreach($allVendors as $vendor)
                                                <option value="{{ $vendor->id }}" @selected($vendorLink->vendor_id === $vendor->id)>
                                                    {{ $vendor->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Link</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">
                                    Run a catalogue synchronization to discover Cloud Factory Vendor mappings.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Provider offers and Nexum Service controls -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Offers</h2>
            <span class="badge text-bg-light border">{{ number_format($offers->total(), 0, ',', '.') }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 280px">Product</th>
                        <th>Vendor</th>
                        <th style="min-width: 130px">
                            <a href="{{ route('tech.admin.system.integrations.cloudfactory.catalogue', array_merge($sortQuery, ['sort' => 'recurrence_term', 'direction' => $recurrenceSortDirection])) }}"
                               class="link-body-emphasis text-decoration-none text-nowrap"
                               title="Sort by commitment term">
                                Commitment
                                <i class="bi {{ ($filters['sort'] ?? '') === 'recurrence_term' ? (($filters['direction'] ?? '') === 'desc' ? 'bi-sort-down' : 'bi-sort-up') : 'bi-arrow-down-up text-muted' }}"
                                   aria-hidden="true"></i>
                            </a>
                        </th>
                        <th style="min-width: 110px">
                            <a href="{{ route('tech.admin.system.integrations.cloudfactory.catalogue', array_merge($sortQuery, ['sort' => 'billing_term', 'direction' => $billingSortDirection])) }}"
                               class="link-body-emphasis text-decoration-none text-nowrap"
                               title="Sort by billing term">
                                Billing
                                <i class="bi {{ ($filters['sort'] ?? '') === 'billing_term' ? (($filters['direction'] ?? '') === 'desc' ? 'bi-sort-down' : 'bi-sort-up') : 'bi-arrow-down-up text-muted' }}"
                                   aria-hidden="true"></i>
                            </a>
                        </th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">MSRP</th>
                        <th>Subscriptions</th>
                        <th style="min-width: 165px">Nexum resale</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($offers as $offer)
                        <tr class="align-middle">
                            <td>
                                <div class="fw-semibold">{{ $offer->name }}</div>
                                <div class="small text-muted">{{ $offer->sku ?: $offer->external_product_id }}</div>
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    @if($offer->deprecated)<span class="badge text-bg-secondary">Deprecated</span>@endif
                                    @if(!$offer->purchasable)<span class="badge text-bg-warning">Not purchasable</span>@endif
                                </div>
                            </td>
                            <td>
                                @if($offer->vendor)
                                    <a href="{{ route('tech.documentations.vendors.show', $offer->vendor) }}">
                                        {{ $offer->vendor->name }}
                                    </a>
                                @else
                                    <span class="badge text-bg-warning">Unmapped</span>
                                @endif
                            </td>
                            <td>
                                <span class="text-nowrap">{{ $offer->commitmentLabel() ?: "\u{2014}" }}</span>
                            </td>
                            <td>
                                <span class="text-nowrap">{{ $offer->billingLabel() ?: "\u{2014}" }}</span>
                            </td>
                            <td class="text-end text-nowrap">
                                <div class="fw-semibold">
                                    {{ $offer->normalizedCost() !== null ? number_format($offer->normalizedCost(), 2, ',', '.') : '—' }}
                                    {{ $offer->currency }}
                                </div>
                                <div class="small text-muted">
                                    Nexum / {{ str($offer->commercialBillingInterval())->replace('_', ' ') }}
                                </div>
                                <div class="small text-muted">
                                    Provider term:
                                    {{ $offer->cost !== null ? number_format((float) $offer->cost, 2, ',', '.') : '—' }}
                                </div>
                            </td>
                            <td class="text-end text-nowrap">
                                <div class="fw-semibold">
                                    {{ $offer->normalizedMsrp() !== null ? number_format($offer->normalizedMsrp(), 2, ',', '.') : '—' }}
                                    {{ $offer->currency }}
                                </div>
                                <div class="small text-muted">
                                    Nexum / {{ str($offer->commercialBillingInterval())->replace('_', ' ') }}
                                </div>
                                <div class="small text-muted">
                                    Provider term:
                                    {{ $offer->msrp !== null ? number_format((float) $offer->msrp, 2, ',', '.') : '—' }}
                                </div>
                            </td>
                            <td>
                                <span class="badge text-bg-{{ $offer->active_subscription_count > 0 ? 'success' : 'light' }}">
                                    {{ $offer->active_subscription_count }}
                                </span>
                            </td>
                            <td>
                                <div class="d-flex flex-column align-items-start gap-1">
                                    @if($offer->sell_enabled)
                                        <span class="badge text-bg-success">For sale</span>
                                    @elseif($offer->excluded)
                                        <span class="badge text-bg-secondary">Excluded</span>
                                    @else
                                        <span class="badge text-bg-light border">Catalogue only</span>
                                    @endif

                                    @if($offer->service)
                                        <div class="small text-muted">
                                            Service {{ $offer->service->sku }} &middot;
                                            {{ number_format((float) $offer->service->price_ex_vat, 2, ',', '.') }}
                                            {{ $offer->service->price_currency }}
                                        </div>
                                    @else
                                        <div class="small text-muted">Not in Services</div>
                                    @endif

                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary mt-1"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#offer-settings-{{ $offer->id }}"
                                            aria-expanded="false"
                                            aria-controls="offer-settings-{{ $offer->id }}">
                                        <i class="bi bi-sliders" aria-hidden="true"></i>
                                        {{ $offer->sell_enabled || $offer->service_id ? 'Edit resale' : 'Set up for sale' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="8" class="p-0 border-0">
                                <div id="offer-settings-{{ $offer->id }}" class="collapse">
                                    <div class="bg-body-tertiary border-top border-bottom px-3 py-3">
                                        <div class="small fw-semibold mb-2">{{ $offer->name }} &middot; Nexum resale settings</div>
                                        <form action="{{ route('tech.admin.system.integrations.cloudfactory.catalogue.update', $offer) }}"
                                              method="POST" class="row g-2 align-items-end">
                                            @csrf
                                            @method('PATCH')
                                            <div class="col-xl-2 col-lg-3 col-md-6">
                                                <label class="form-label small mb-1" for="price_mode_{{ $offer->id }}">Price rule</label>
                                                <select id="price_mode_{{ $offer->id }}" name="price_mode" class="form-select form-select-sm">
                                                    <option value="">Use global</option>
                                                    <option value="follow_msrp" @selected($offer->price_mode === 'follow_msrp')>MSRP</option>
                                                    <option value="msrp_markup" @selected($offer->price_mode === 'msrp_markup')>MSRP + %</option>
                                                    <option value="cost_markup" @selected($offer->price_mode === 'cost_markup')>Cost + %</option>
                                                    <option value="manual" @selected($offer->price_mode === 'manual')>Manual</option>
                                                </select>
                                            </div>
                                            <div class="col-xl-2 col-lg-3 col-md-6">
                                                <label class="form-label small mb-1" for="markup_{{ $offer->id }}">Markup %</label>
                                                <input id="markup_{{ $offer->id }}" name="markup_percent" type="number" step="0.01"
                                                       class="form-control form-control-sm" value="{{ $offer->markup_percent }}">
                                            </div>
                                            <div class="col-xl-2 col-lg-3 col-md-6">
                                                <label class="form-label small mb-1" for="manual_{{ $offer->id }}">Manual price</label>
                                                <input id="manual_{{ $offer->id }}" name="manual_sale_price" type="number" step="0.01" min="0"
                                                       class="form-control form-control-sm" value="{{ $offer->manual_sale_price }}">
                                            </div>
                                            <div class="col-xl-4 col-lg-6 col-md-8">
                                                <label class="form-label small mb-1">Availability</label>
                                                <div class="d-flex flex-wrap gap-3">
                                                    <div class="form-check form-switch mb-0">
                                                        <input type="hidden" name="sell_enabled" value="0">
                                                        <input id="sell_{{ $offer->id }}" name="sell_enabled" value="1" type="checkbox"
                                                               class="form-check-input" @checked($offer->sell_enabled)>
                                                        <label for="sell_{{ $offer->id }}" class="form-check-label small">For sale</label>
                                                    </div>
                                                    <div class="form-check form-switch mb-0">
                                                        <input type="hidden" name="excluded" value="0">
                                                        <input id="exclude_{{ $offer->id }}" name="excluded" value="1" type="checkbox"
                                                               class="form-check-input" @checked($offer->excluded)
                                                               @disabled($offer->active_subscription_count > 0)>
                                                        <label for="exclude_{{ $offer->id }}" class="form-check-label small">Exclude</label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-xl-2 col-lg-3">
                                                <button type="submit" class="btn btn-sm btn-primary w-100">Save</button>
                                            </div>
                                        </form>
                                        <div class="small text-muted mt-2">
                                            For sale creates or updates the ordinary Nexum Service. Exclude hides the offer from new sales.
                                            Offers with active subscriptions remain available for contract and billing history.
                                            Each commitment and billing variant creates its own Service SKU, Cost, sale price, and
                                            billing interval.
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No catalogue offers match the current filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($offers->hasPages())
            <div class="card-footer">{{ $offers->links() }}</div>
        @endif
    </div>
@endsection
