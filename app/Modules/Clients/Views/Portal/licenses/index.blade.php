@extends('customerportal::layouts.portal')

@section('title', 'Licences')

@section('content')
    @php
        $scopeAllowsWrite = ($settings['write_scope'] ?? 'test_client') === 'all'
            || (int) ($settings['test_client_id'] ?? 0) === (int) $context->client->id;
        $canWrite = $integration->status === 'active'
            && ($settings['writes_enabled'] ?? false)
            && $scopeAllowsWrite;
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h1 class="h4 mb-1">Licences</h1>
            <div class="small text-muted">{{ $context->client->name }}</div>
        </div>
        <span class="badge text-bg-{{ $canWrite ? 'success' : 'secondary' }}">
            Cloud Factory {{ $canWrite ? 'ordering available' : 'ordering unavailable' }}
        </span>
    </div>

    @unless($canWrite)
        <div class="alert alert-warning">
            Licence ordering is temporarily unavailable. Your existing subscriptions remain visible below.
        </div>
    @endunless

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-body">
            <h2 class="h6 mb-0">Order a licence already covered by contract</h2>
            <div class="small text-muted">Only exact Cloud Factory Service variants on an active accepted contract are available.</div>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('customer-portal.licenses.issue') }}">
                @csrf
                <div class="row g-2">
                    <div class="col-lg-7">
                        <label for="contract_item_id" class="form-label small">Contract product</label>
                        <select id="contract_item_id" name="contract_item_id" class="form-select form-select-sm" required>
                            <option value="">Choose a contracted product</option>
                            @foreach($contractItems as $item)
                                <option value="{{ $item->id }}" @selected(old('contract_item_id') == $item->id)>
                                    {{ $item->name }} ? {{ $item->cloudFactoryOffer?->commitmentLabel() ?: 'No stated commitment' }} ? {{ number_format((float) $item->unit_price, 2, ',', ' ') }} kr
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <label for="licence_quantity" class="form-label small">Quantity</label>
                        <input id="licence_quantity" type="number" min="1" max="100000" name="quantity" value="{{ old('quantity', 1) }}" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-lg-3">
                        <label for="licence_name" class="form-label small">Confirmed by</label>
                        <input id="licence_name" type="text" name="name" value="{{ old('name', $context->contact->display_name) }}" class="form-control form-control-sm" required>
                    </div>
                </div>

                <div class="border rounded p-2 mt-3 small">
                    <div class="fw-semibold mb-1">Legal confirmation</div>
                    <div class="text-muted mb-2">
                        The order records the exact product, price, quantity, commitment and current document versions.
                        Provider documents are read-only; additional Nexum terms come from the approved legal library.
                    </div>
                    <div class="form-check">
                        <input id="licence_confirm" type="checkbox" name="confirm" value="1" class="form-check-input" required>
                        <label for="licence_confirm" class="form-check-label">
                            I confirm this order and accept the current legal documents attached to the selected contracted product.
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-sm mt-3" @disabled(! $canWrite || $contractItems->isEmpty())>
                    Order licence
                </button>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-body">
            <h2 class="h6 mb-0">Contracted products and legal documents</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th>Contract</th>
                        <th>Commercial terms</th>
                        <th>Current documents</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contractItems as $item)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $item->name }}</div>
                                <div class="small text-muted">{{ $item->sku }}</div>
                            </td>
                            <td>#{{ $item->contract_id }}</td>
                            <td class="small">
                                {{ $item->cloudFactoryOffer?->commitmentLabel() ?: 'Not stated' }} /
                                {{ $item->cloudFactoryOffer?->billingLabel() ?: 'Not stated' }}
                            </td>
                            <td>
                                @forelse($item->service?->serviceTerms ?? [] as $term)
                                    <div class="small">
                                        {{ $term->currentVersion?->name ?? $term->name }}
                                        <span class="text-muted">v{{ $term->currentVersion?->version_label ?: '1' }}</span>
                                        @if($term->currentVersion?->source_url ?? $term->source_url)
                                            <a href="{{ $term->currentVersion?->source_url ?? $term->source_url }}" target="_blank" rel="noopener" aria-label="Open document">
                                                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                            </a>
                                        @endif
                                    </div>
                                @empty
                                    <span class="small text-muted">No separate product document supplied.</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">No orderable Cloud Factory products are covered by an active accepted contract.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-body">
            <h2 class="h6 mb-0">Current licences</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Licence</th>
                        <th>Status</th>
                        <th>Quantity</th>
                        <th>Renewal</th>
                        <th>Commitment</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subscriptions as $subscription)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $subscription->name }}</div>
                                <div class="small text-muted">{{ $subscription->offer?->vendor?->name ?? ucfirst($subscription->provider_family) }}</div>
                                @foreach($subscription->service?->serviceTerms ?? [] as $term)
                                    <div class="small text-muted">
                                        {{ $term->currentVersion?->name ?? $term->name }} v{{ $term->currentVersion?->version_label ?: '1' }}
                                    </div>
                                @endforeach
                            </td>
                            <td><span class="badge text-bg-light border">{{ ucfirst($subscription->status) }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('customer-portal.licenses.quantity', $subscription) }}" class="d-flex flex-wrap gap-1">
                                    @csrf
                                    @method('PATCH')
                                    <input type="number" name="quantity" value="{{ $subscription->quantity }}" min="0" max="100000" class="form-control form-control-sm" style="width: 6rem;" required>
                                    <input type="hidden" name="name" value="{{ $context->contact->display_name }}">
                                    <label class="btn btn-sm btn-outline-primary mb-0">
                                        <input type="checkbox" name="confirm" value="1" class="visually-hidden" onchange="this.form.requestSubmit()" @disabled(! $canWrite)>
                                        Confirm change
                                    </label>
                                </form>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('customer-portal.licenses.renewal', $subscription) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="enabled" value="{{ $subscription->auto_renew ? 0 : 1 }}">
                                    <input type="hidden" name="name" value="{{ $context->contact->display_name }}">
                                    <label class="btn btn-sm btn-outline-secondary mb-0">
                                        <input type="checkbox" name="confirm" value="1" class="visually-hidden" onchange="this.form.requestSubmit()" @disabled(! $canWrite)>
                                        {{ $subscription->auto_renew ? 'Disable' : 'Enable' }} renewal
                                    </label>
                                </form>
                            </td>
                            <td class="small">
                                {{ $subscription->commitment_end_date?->format('Y-m-d') ?: 'Not stated' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No Cloud Factory licences are synchronized for this client.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="small text-muted mt-3 mb-0">
        Every portal write requires an explicit confirmation. Automatic renewals already authorized by the accepted contract do not prompt again unless a portal user changes the setting.
    </p>
@endsection
