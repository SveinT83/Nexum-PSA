@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <div>
            <h2 class="h4 mb-0">Contract #{{ $contract->id }} - Add Services</h2>
            <p class="text-muted mb-0 small">Client: <strong>{{ $client->name }}</strong> ({{ $client->client_number }})</p>
        </div>
        <div>
            <a href="{{ route('tech.contracts.index') }}" class="btn btn-sm btn-secondary bi bi-arrow-left-short"> Back</a>
        </div>
    </div>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- Services -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Services">
        <livewire:tech.cs.contracts.contract-items-editor :contract="$contract" />
    </x-card.default>

    <!-- ------------------------------------------------- -->
    <!-- Contract description -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Description">
        {{$contract->description ?? 'No description is provided'}}
    </x-card.default>

@endsection

@section('sidebar')
    <div class="p-3 small text-muted">Service filters (later)</div>
@endsection

@section('rightbar')

    <!-- ------------------------------------------------- -->
    <!-- Contract data -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Details">
        @if($contract->isEditable())
            <div class="mb-3 d-grid gap-2">
                <a href="{{ route('tech.contracts.edit', $contract) }}" class="btn btn-sm btn-outline-warning bi bi-pencil-square text-start"> Edit Details</a>
                <a href="{{ route('tech.contracts.terms', $contract) }}" class="btn btn-sm btn-outline-warning bi bi-file-text text-start"> Edit Terms</a>
            </div>
        @endif
        <div class="mb-2">
            <span class="text-muted d-block small uppercase font-weight-bold mb-1">Status:</span>
            @php
                $statusClass = match($contract->approval_status) {
                    'approved', 'won' => 'success',
                    'rejected', 'quote_lost' => 'danger',
                    'draft' => 'secondary',
                    'negotiation' => 'info',
                    'sent_quote', 'sent_contract' => 'primary',
                    default => 'secondary'
                };
                $statusLabel = match($contract->approval_status) {
                    'quote_lost' => 'Quote Lost',
                    'sent_quote' => 'Sent (Quote)',
                    'sent_contract' => 'Sent (Contract)',
                    default => ucfirst(str_replace('_', ' ', $contract->approval_status ?? 'Draft'))
                };
            @endphp
            <span class="badge text-bg-{{ $statusClass }}">
                {{ $statusLabel }}
            </span>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-6">
                <span class="text-muted d-block small uppercase font-weight-bold mb-1">Start Date:</span>
                <p class="mb-0">{{ $contract->start_date?->format('d.m.Y') ?? 'N/A' }}</p>
            </div>
            <div class="col-6">
                <span class="text-muted d-block small uppercase font-weight-bold mb-1">End Date:</span>
                <p class="mb-0">{{ $contract->end_date?->format('d.m.Y') ?? 'Open' }}</p>
            </div>
        </div>

        <div class="mb-2">
            <span class="text-muted d-block small uppercase font-weight-bold mb-1">Binding End:</span>
            <p class="mb-0">{{ $contract->binding_end_date?->format('d.m.Y') ?? 'N/A' }}</p>
        </div>

        <div class="mb-0 pt-2 border-top mt-2">
            <span class="text-muted d-block small uppercase font-weight-bold mb-1">Auto-Renew:</span>
            <p class="mb-0">
                @if($contract->auto_renew)
                    <span class="text-success bi bi-check-circle-fill"> Yes</span>
                    @if($contract->renewal_months)
                        <span class="text-muted small">({{ $contract->renewal_months }} months)</span>
                    @endif
                @else
                    <span class="text-danger bi bi-x-circle-fill"> No</span>
                @endif
            </p>
        </div>
    </x-card.default>

    <!-- ------------------------------------------------- -->
    <!-- Client details -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Client Details">
        <div class="mb-3">
            <span class="text-muted d-block small uppercase font-weight-bold mb-1">Company:</span>
            <p class="mb-0 fw-bold">{{ $client->name }}</p>
            <span class="text-muted small">#{{ $client->client_number }}</span>
        </div>

        <div class="row g-2 mb-3 pt-2 border-top">
            <div class="col-6">
                <span class="text-muted d-block small uppercase font-weight-bold mb-1">Users:</span>
                <p class="mb-0 h5"><i class="bi bi-people me-1"></i>{{ $client->contacts()->count() }}</p>
            </div>
            <div class="col-6">
                <span class="text-muted d-block small uppercase font-weight-bold mb-1">Sites:</span>
                <p class="mb-0 h5"><i class="bi bi-building me-1"></i>{{ $client->sites()->count() }}</p>
            </div>
        </div>

        @if($client->org_no)
            <div class="mb-2 pt-2 border-top">
                <span class="text-muted d-block small uppercase font-weight-bold mb-1">Org No:</span>
                <p class="mb-0">{{ $client->org_no }}</p>
            </div>
        @endif

        @if($client->billing_email)
            <div class="mb-0 pt-2 border-top">
                <span class="text-muted d-block small uppercase font-weight-bold mb-1">Billing Email:</span>
                <p class="mb-0 small text-truncate" title="{{ $client->billing_email }}">
                    <i class="bi bi-envelope me-1"></i>{{ $client->billing_email }}
                </p>
            </div>
        @endif
    </x-card.default>

@endsection
