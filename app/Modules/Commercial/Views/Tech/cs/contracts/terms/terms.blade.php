@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <div>
            <h2 class="h4 mb-0">Contract #{{ $contract->id }} - Terms & Legal</h2>
            <p class="text-muted mb-0 small">Client: <strong>{{ $client->name }}</strong> ({{ $client->client_number }})</p>
        </div>
        <div>
            <a href="{{ route('tech.contracts.services.edit', $contract) }}" class="btn btn-sm btn-secondary bi bi-arrow-left-short"> Back to Services</a>
        </div>
    </div>
@endsection

@section('content')
    <form action="{{ route('tech.contracts.terms.update', $contract) }}" method="POST">
        @csrf
        <div class="row">
            <div class="col-md-12">
                <x-card.default title="Legal & Terms Snapshots">
                    <p class="text-muted small mb-3">
                        These terms are gathered from the services added to the contract.
                        You can edit them here before saving them as a snapshot for this specific contract.
                    </p>

                    <ul class="nav nav-tabs mb-4" id="termsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="terms-edit-tab" data-bs-toggle="tab" data-bs-target="#terms-edit-pane" type="button" role="tab">General Terms</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="dpa-edit-tab" data-bs-toggle="tab" data-bs-target="#dpa-edit-pane" type="button" role="tab">DPA</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="legal-edit-tab" data-bs-toggle="tab" data-bs-target="#legal-edit-pane" type="button" role="tab">Legal & GDPR</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="sla-edit-tab" data-bs-toggle="tab" data-bs-target="#sla-edit-pane" type="button" role="tab">SLA</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="general-edit-tab" data-bs-toggle="tab" data-bs-target="#general-edit-pane" type="button" role="tab">General</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="termsTabsContent">
                        <div class="tab-pane fade show active" id="terms-edit-pane" role="tabpanel">
                            <div class="mb-3">
                                <label for="terms_snapshot" class="form-label fw-bold small">General Terms Content</label>
                                <textarea name="terms_snapshot" id="terms_snapshot" class="form-control" rows="15">{{ $contract->terms_snapshot }}</textarea>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="dpa-edit-pane" role="tabpanel">
                            <div class="mb-3">
                                <label for="dpa_snapshot" class="form-label fw-bold small">DPA Content</label>
                                <textarea name="dpa_snapshot" id="dpa_snapshot" class="form-control" rows="15">{{ $contract->dpa_snapshot }}</textarea>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="legal-edit-pane" role="tabpanel">
                            <div class="mb-3">
                                <label for="legal_snapshot" class="form-label fw-bold small">Legal & GDPR Content</label>
                                <textarea name="legal_snapshot" id="legal_snapshot" class="form-control" rows="15">{{ $contract->legal_snapshot }}</textarea>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="sla-edit-pane" role="tabpanel">
                            <div class="mb-3">
                                <label for="sla_snapshot" class="form-label fw-bold small">SLA Content</label>
                                <textarea name="sla_snapshot" id="sla_snapshot" class="form-control" rows="15">{{ $contract->sla_snapshot }}</textarea>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="general-edit-pane" role="tabpanel">
                            <div class="mb-3">
                                <label for="general_snapshot" class="form-label fw-bold small">General Content</label>
                                <textarea name="general_snapshot" id="general_snapshot" class="form-control" rows="15">{{ $contract->general_snapshot }}</textarea>
                            </div>
                        </div>
                    </div>
                </x-card.default>

                <div class="mt-4 mb-5 d-flex justify-content-between align-items-center">
                    <div>
                        <a href="{{ route('tech.contracts.terms', [$contract, 'refresh' => 1]) }}" class="btn btn-outline-secondary btn-sm" onclick="return confirm('This will overwrite your current snapshots with the latest terms from the services. Are you sure?')">
                            <i class="bi bi-arrow-clockwise me-1"></i> Refresh from Services
                        </a>
                    </div>
                    <button type="submit" class="btn btn-primary px-5">
                        <i class="bi bi-save me-2"></i> Save Snapshot
                    </button>
                </div>
            </div>
        </div>
    </form>
@endsection

@section('rightbar')
    <x-card.default title="Included Services">
        <p class="text-muted small mb-3">Services contributing to these terms:</p>
        <ul class="list-group list-group-flush">
            @foreach($contract->items as $item)
                <li class="list-group-item d-flex justify-content-between align-items-start bg-transparent px-0">
                    <div class="ms-2 me-auto">
                        <div class="fw-bold small">{{ $item->name }}</div>
                        @if($item->service && $item->service->serviceTerms()->count() > 0)
                            <span class="badge bg-info rounded-pill" style="font-size: 0.7rem;">
                                {{ $item->service->serviceTerms()->count() }} term(s)
                            </span>
                        @else
                            <span class="text-muted small">No specific terms</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-card.default>

    <x-card.default title="Details">
        <div class="mb-2">
            <span class="text-muted d-block small uppercase font-weight-bold mb-1">Status:</span>
            @php
                $statusClass = match($contract->approval_status) {
                    'approved' => 'success',
                    'rejected' => 'danger',
                    'draft' => 'secondary',
                    'negotiation' => 'info',
                    'quote_lost' => 'warning',
                    default => 'primary'
                };
            @endphp
            <span class="badge text-bg-{{ $statusClass }}">
                {{ ucfirst($contract->approval_status ?? 'Draft') }}
            </span>
        </div>
    </x-card.default>
@endsection
