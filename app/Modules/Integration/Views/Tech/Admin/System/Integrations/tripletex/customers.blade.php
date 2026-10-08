@extends('layouts.default_tech')
@section('title', 'Tripletex customer links')
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="h3">Tripletex customer links</h1>
        <x-buttons.back :url="route('tech.admin.system.integrations.tripletex.index')" />
    </div>
@endsection
@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection
@section('content')
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    @if(session('profile_notice'))<div class="alert alert-warning" role="status">{{ session('profile_notice') }}</div>@endif
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif

    {{-- Explicit read-only comparison precedes number adoption. --}}
    <section class="card mb-3">
        <div class="card-header">Review an existing customer</div>
        <div class="card-body">
            <form method="get" class="row g-2">
                <div class="col-md-4"><label for="review-client" class="form-label">Nexum Client ID</label>
                    <input id="review-client" class="form-control" type="number" min="1" name="client_id" value="{{ request('client_id') }}" required></div>
                <div class="col-md-4"><label for="review-customer" class="form-label">Tripletex customer ID</label>
                    <input id="review-customer" class="form-control" type="number" min="1" name="customer_id" value="{{ request('customer_id') }}" required></div>
                <div class="col-md-4 align-self-end"><button class="btn btn-outline-primary" type="submit">Review link</button></div>
            </form>
            <p class="form-text mb-0">Use the record IDs from each system. Review the customer names and numbers before applying.</p>
        </div>
    </section>
    @if($review)
        <section class="card mb-3">
            <div class="card-header">Compare and confirm</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6"><strong>Nexum</strong><p>{{ $review['client']->name }} · {{ $review['client']->client_number }}<br>{{ $review['client']->org_no }}</p></div>
                    <div class="col-md-6"><strong>Tripletex</strong><p>{{ $review['remote']['name'] }} · {{ $review['remote']['customerNumber'] }}<br>{{ $review['remote']['organizationNumber'] ?? '' }}</p></div>
                </div>
                <form method="post" action="{{ route('tech.admin.system.integrations.tripletex.customers.link', $connection->id) }}">
                    @csrf
                    <input type="hidden" name="client_id" value="{{ $review['client']->id }}">
                    <input type="hidden" name="customer_id" value="{{ $review['remote']['id'] }}">
                    <input type="hidden" name="expected_local" value="{{ $review['client']->client_number }}">
                    <input type="hidden" name="expected_remote" value="{{ $review['remote']['customerNumber'] }}">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="accept-number" name="accept_change" value="1" required>
                        <label class="form-check-label" for="accept-number">I confirm this is the same customer and accept the Tripletex customer number.</label>
                    </div>
                    <button class="btn btn-primary" type="submit">Save reviewed link</button>
                </form>
            </div>
        </section>
    @endif
    {{-- Site identity is explicit; rebinding starts from provider values without moving contacts. --}}
    @if($review)
        @php
            $profile = $reviewLink ? $profiles->get($reviewLink->id) : null;
        @endphp
        @if($reviewLink)
            <section class="card mb-3">
                <div class="card-header">Synchronized Site address</div>
                <div class="card-body">
                    <form method="post" action="{{ route('tech.admin.system.integrations.tripletex.customers.profile-site', ['connection' => $connection->id, 'link' => $reviewLink->id]) }}">
                        @csrf
                        <input type="hidden" name="expected_site_id" value="{{ $profile?->site_id }}">
                        <label class="form-label" for="profile-site">Site</label>
                        <select class="form-select mb-2" id="profile-site" name="site_id" required>
                            @foreach($review['client']->sites as $site)
                                <option value="{{ $site->id }}" @selected($profile?->site_id === $site->id)>{{ $site->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="confirm-profile-site" name="confirm_site" value="1" required>
                            <label class="form-check-label" for="confirm-profile-site">Use Tripletex values for this Site address. Contacts stay unchanged.</label>
                        </div>
                        <button class="btn btn-outline-primary" type="submit">Save Site binding</button>
                    </form>
                </div>
            </section>
        @endif
    @endif

    {{-- Saved attempt state is evidence; a successful POST alone is never labelled linked. --}}
    <section class="card mb-3">
        <div class="card-header">Links and creation attempts</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0"><thead><tr><th>Attempt</th><th>Nexum Client</th><th>Tripletex customer</th><th>Number</th><th>Status / last checked</th><th>Billing / Site sync</th><th>Action</th></tr></thead>
                <tbody>@forelse($links as $link)
                    <tr><td>{{ $link->id }}</td><td>{{ $link->client_id ?? 'Not created' }}</td><td>{{ $link->customer_id ?? 'Unknown' }}</td><td>{{ $link->customer_number ?? 'Not assigned' }}</td>
                        <td>{{ $link->status }}<br>{{ $link->checked_at ?? 'Not verified' }}@if($link->error_code)<br>{{ str_replace('_', ' ', $link->error_code) }}@endif</td>
                        <td>
                            @php($profile = $profiles->get($link->id))
                            {{ $profile?->status ?? 'Awaiting first scan' }}
                            @if($profile?->checked_at)<br>{{ $profile->checked_at }}@endif
                            @if($profile?->error_code)<br>{{ str_replace('_', ' ', $profile->error_code) }}@endif
                            @if($profile?->conflict_fields)<br>Tripletex retained: {{ implode(', ', $profile->conflict_fields) }}@endif
                        </td>
                        <td>
                            @if($link->client_id && $link->customer_id && $connection->status === 'active' && ($connection->config['customer_sync_enabled'] ?? false))
                                <form method="post" action="{{ route('tech.admin.system.integrations.tripletex.customers.sync-profile', ['connection' => $connection->id, 'link' => $link->id]) }}" class="mb-1">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary" type="submit">Sync billing and address</button>
                                </form>
                            @endif
                            @if($link->client_id && $link->customer_id)<a href="{{ route('tech.admin.system.integrations.tripletex.customers', ['connection' => $connection->id, 'client_id' => $link->client_id, 'customer_id' => $link->customer_id]) }}">Review current numbers</a>@endif</td></tr>
                @empty<tr><td colspan="7">No customer links or creation attempts.</td></tr>@endforelse</tbody>
            </table>
        </div>
        <div class="card-footer">{{ $links->links() }}</div>
    </section>
    {{-- Recovery never resends a provider POST. --}}
    <details>
        <summary>Reconcile an unknown creation outcome</summary>
        <p class="mt-2">Verify the intended customer in Tripletex first. Record its ID and the attempted number below, then resubmit the original Nexum form. If no customer exists, keep the attempt unresolved until the provider outcome is confirmed.</p>
        <form method="post" action="{{ route('tech.admin.system.integrations.tripletex.customers.recover', $connection->id) }}" class="row g-2">
            @csrf
            <div class="col-md-4"><label for="recover-attempt" class="form-label">Attempt ID</label><input id="recover-attempt" class="form-control" name="attempt_id" type="number" min="1" required></div>
            <div class="col-md-4"><label for="recover-customer" class="form-label">Tripletex customer ID</label><input id="recover-customer" class="form-control" name="customer_id" type="number" min="1" required></div>
            <div class="col-md-4"><label for="recover-number" class="form-label">Verified customer number</label><input id="recover-number" class="form-control" name="expected_number" required></div>
            <div class="col-12 form-check ms-2"><input id="recover-confirm" class="form-check-input" type="checkbox" name="confirm_identity" value="1" required><label for="recover-confirm" class="form-check-label">I verified this is the customer created by this attempt.</label></div>
            <div class="col-12"><button class="btn btn-outline-primary" type="submit">Record verified provider identity</button></div>
        </form>
    </details>
@endsection
