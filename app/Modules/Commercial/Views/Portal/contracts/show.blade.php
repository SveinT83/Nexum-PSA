@extends('customerportal::layouts.portal')

@section('title', $customerDocument['document']['type'].' #'.$customerDocument['document']['contract_number'])

@section('content')
    <!-- Customer Portal contract detail -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $customerDocument['document']['type'] }} #{{ $customerDocument['document']['contract_number'] }}</h1>
            <div class="small text-muted">{{ $customerDocument['parties']['customer']['name'] }}</div>
        </div>
        <a href="{{ route('customer-portal.contracts.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Tilbake
        </a>
    </div>

    @include('commercial::Shared.customer-document-web', ['customerDocument' => $customerDocument])

    @if($access->canAccept($contract))
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-body">
                <h2 class="h6 mb-0">Godkjenn dokumentet</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('customer-portal.contracts.accept', $contract) }}">
                    @csrf
                    <label for="contract_accept_name" class="form-label">Fullt navn</label>
                    <input id="contract_accept_name" type="text" name="name" class="form-control mb-3" value="{{ old('name', $context->contact->display_name) }}" required>
                    <div class="form-check mb-3">
                        <input type="checkbox" name="confirm" value="1" id="contract_confirm" class="form-check-input" required>
                        <label for="contract_confirm" class="form-check-label">
                            Jeg bekrefter at jeg har lest og godtar dokumentet med oppførte vilkår og vedlegg.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Godkjenn
                    </button>
                </form>
            </div>
        </div>
    @endif
@endsection
