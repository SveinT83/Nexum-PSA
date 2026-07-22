@extends('layouts.default_tech')

@section('title', 'Licences - '.$client->name)

@section('pageHeader')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="small text-muted">{{ $client->client_number }}</div>
            <h1 class="h3 mb-0">{{ $client->name }} - Licences</h1>
        </div>
        <a href="{{ route('tech.clients.show', $client) }}" class="btn btn-outline-secondary">Client overview</a>
    </div>
@endsection

@section('content')
    @php
        $canWrite = auth()->user()?->can('integration.cloudfactory_write')
            && $integration->status === 'active'
            && ($settings['writes_enabled'] ?? false);
        $testRestricted = ($settings['write_scope'] ?? 'test_client') === 'test_client';
        $isAllowlisted = (int) ($settings['test_client_id'] ?? 0) === (int) $client->id;
    @endphp

    <!-- Provider link and synchronization state -->
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="fw-semibold">Cloud Factory customer</div>
                @if($link)
                    <div class="text-muted">{{ $link->external_customer_id }}</div>
                    <div class="small text-muted">
                        Linked by {{ str_replace('_', ' ', $link->match_method ?: 'sync') }}
                        ? Last sync {{ $link->last_synced_at?->diffForHumans() ?: 'pending' }}
                    </div>
                @else
                    <div class="text-muted">
                        Not linked yet. Nexum creates and links the Cloud Factory customer automatically
                        when the first contract-approved licence is issued.
                    </div>
                @endif
            </div>
            <div class="d-flex gap-2">
                <span class="badge text-bg-{{ $integration->status === 'active' ? 'success' : 'secondary' }}">
                    Cloud Factory {{ $integration->status }}
                </span>
                @if($testRestricted)
                    <span class="badge text-bg-{{ $isAllowlisted ? 'warning' : 'light' }}">
                        {{ $isAllowlisted ? 'Fictitious test Client' : 'Writes test-restricted' }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    @if($integration->status !== 'active')
        <div class="alert alert-warning">Cloud Factory is not connected. Existing synchronized state remains read-only.</div>
    @elseif($testRestricted && !$isAllowlisted)
        <div class="alert alert-info">
            Provider writes are still limited to the configured fictitious Client. This Client is read-only until that validation is completed.
        </div>
    @endif

    <!-- Contract-gated new licence issue -->
    @if($canWrite && (!$testRestricted || $isAllowlisted))
        <form action="{{ route('tech.clients.licenses.issue', $client) }}" method="POST" class="card mb-3">
            @csrf
            <div class="card-header"><h2 class="h5 mb-0">Issue a new licence</h2></div>
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-8">
                        <label for="offer_id" class="form-label">Cloud Factory product</label>
                        <select id="offer_id" name="offer_id" class="form-select" required>
                            <option value="">Select an enabled catalogue offer</option>
                            @foreach($offers as $offer)
                                <option value="{{ $offer->id }}">
                                    {{ $offer->vendor?->name ?: ucfirst($offer->provider_family) }}
                                    ? {{ $offer->name }}
                                    ? {{ number_format((float) $offer->service->price_ex_vat, 2, ',', '.') }} {{ $offer->currency }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <label for="issue_quantity" class="form-label">Quantity</label>
                        <input id="issue_quantity" name="quantity" type="number" min="1" value="1" class="form-control" required>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button type="submit" class="btn btn-primary">Issue licence</button>
                    </div>
                </div>
                <div class="form-text">
                    Nexum requires a won active contract with the selected Service line. If the Cloud Factory customer is missing,
                    it is created automatically before provisioning. Provider activation must be reconciled before billing starts.
                </div>
            </div>
        </form>
    @endif

    <!-- Current normalized licence state -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Current licences</h2>
            <span class="badge text-bg-light border">{{ $subscriptions->count() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Licence</th>
                        <th>Provider</th>
                        <th>Quantity</th>
                        <th>Commitment / renewal</th>
                        <th>Contract and billing</th>
                        <th class="text-end">Price</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($subscriptions as $subscription)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $subscription->name ?: $subscription->offer?->name ?: 'Licence' }}</div>
                                <div class="small text-muted">{{ $subscription->external_subscription_id }}</div>
                                <span class="badge text-bg-{{ in_array($subscription->status, ['active', 'enabled', 'provisioned', 'committed']) ? 'success' : 'secondary' }}">
                                    {{ $subscription->status }}
                                </span>
                            </td>
                            <td>
                                <div>{{ $subscription->offer?->vendor?->name ?: ucfirst($subscription->provider_family) }}</div>
                                <div class="small text-muted">Source: Cloud Factory</div>
                            </td>
                            <td>
                                <div class="fw-semibold">{{ $subscription->quantity }}</div>
                                @if($subscription->used_quantity !== null)
                                    <div class="small text-muted">{{ $subscription->used_quantity }} used</div>
                                @endif
                            </td>
                            <td>
                                <div>{{ $subscription->commitment_end_date?->format('d.m.Y') ?: 'No end date received' }}</div>
                                <div class="small text-muted">
                                    Renewal {{ $subscription->renewal_date?->format('d.m.Y') ?: '' }}
                                    ? Auto {{ $subscription->auto_renew === null ? 'unknown' : ($subscription->auto_renew ? 'on' : 'off') }}
                                </div>
                            </td>
                            <td>
                                @if($subscription->contract)
                                    <a href="{{ route('tech.contracts.show', $subscription->contract) }}">
                                        {{ $subscription->contract->description ?: 'Contract #'.$subscription->contract->id }}
                                    </a>
                                @else
                                    <span class="text-danger">No eligible contract link</span>
                                @endif
                                <div>
                                    <span class="badge text-bg-{{ $subscription->billing_state === 'confirmed' ? 'success' : ($subscription->billing_state === 'blocked' ? 'danger' : 'warning') }}">
                                        Billing {{ $subscription->billing_state }}
                                    </span>
                                </div>
                            </td>
                            <td class="text-end text-nowrap">
                                {{ $subscription->unit_cost !== null ? number_format((float) $subscription->unit_cost, 2, ',', '.') : '' }}
                                <span class="text-muted">cost</span><br>
                                {{ $subscription->unit_sale_price !== null ? number_format((float) $subscription->unit_sale_price, 2, ',', '.') : '' }}
                                {{ $subscription->currency }} <span class="text-muted">sale</span>
                            </td>
                            <td style="min-width: 250px">
                                @if($canWrite && (!$testRestricted || $isAllowlisted) && $subscription->contract)
                                    <form action="{{ route('tech.clients.licenses.quantity', [$client, $subscription]) }}" method="POST" class="d-flex gap-1 mb-1">
                                        @csrf
                                        @method('PATCH')
                                        <input name="quantity" type="number"
                                               min="{{ $subscription->provider_family === 'adobe' ? $subscription->quantity : 0 }}"
                                               value="{{ $subscription->quantity }}" class="form-control form-control-sm" required>
                                        <button class="btn btn-sm btn-outline-primary">Quantity</button>
                                    </form>
                                    <div class="d-flex flex-wrap gap-1">
                                        <form action="{{ route('tech.clients.licenses.renewal', [$client, $subscription]) }}" method="POST">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="enabled" value="{{ $subscription->auto_renew ? 0 : 1 }}">
                                            <button class="btn btn-sm btn-outline-secondary">
                                                {{ $subscription->auto_renew ? 'Stop renewal' : 'Enable renewal' }}
                                            </button>
                                        </form>
                                        @if($subscription->provider_family === 'microsoft')
                                            <form action="{{ route('tech.clients.licenses.status', [$client, $subscription]) }}" method="POST">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="status" value="{{ $subscription->status === 'suspended' ? 'activate' : 'suspend' }}">
                                                <button class="btn btn-sm btn-outline-secondary">
                                                    {{ $subscription->status === 'suspended' ? 'Activate' : 'Suspend' }}
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                    @if($subscription->provider_family === 'adobe')
                                        <div class="small text-muted mt-1">Immediate Adobe decreases are hidden because Cloud Factory does not publish that operation.</div>
                                    @endif
                                @else
                                    <span class="small text-muted">Read-only until write and contract gates pass.</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No Cloud Factory licences are synchronized for this Client.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Operation audit history -->
    <div class="card">
        <div class="card-header"><h2 class="h5 mb-0">Licence operation history</h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Created</th><th>Action</th><th>Provider</th><th>Status</th><th>Submitted</th><th>Confirmed</th></tr></thead>
                <tbody>
                    @forelse($operations as $operation)
                        <tr>
                            <td>{{ $operation->created_at->format('d.m.Y H:i') }}</td>
                            <td>{{ ucfirst($operation->action) }}</td>
                            <td>{{ ucfirst($operation->provider_family ?: 'Cloud Factory') }}</td>
                            <td><span class="badge text-bg-{{ $operation->status === 'confirmed' ? 'success' : ($operation->status === 'failed' ? 'danger' : 'warning') }}">{{ $operation->status }}</span></td>
                            <td>{{ $operation->submitted_at?->format('d.m.Y H:i') ?: '' }}</td>
                            <td>{{ $operation->confirmed_at?->format('d.m.Y H:i') ?: '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No licence operations.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
