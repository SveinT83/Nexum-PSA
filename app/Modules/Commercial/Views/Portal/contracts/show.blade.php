@extends('customerportal::layouts.portal')

@section('title', 'Contract #'.$contract->id)

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Contract Detail -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Contract #{{ $contract->id }}</h1>
            <div class="small text-muted">{{ $context->client->name }}</div>
        </div>
        <a href="{{ route('customer-portal.contracts.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Back
        </a>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Included services</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Service</th>
                                <th>SLA</th>
                                <th class="text-end">Qty</th>
                                <th>Interval</th>
                                <th class="text-end">Unit price</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($contract->items as $item)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $item->name }}</div>
                                        <div class="small text-muted">{{ $item->sku ?: '-' }}</div>
                                    </td>
                                    <td>
                                        @if($item->uses_contract_default_sla)
                                            <span class="badge text-bg-light border">{{ $contract->sla?->name ?? 'Contract default' }}</span>
                                        @else
                                            <span class="badge text-bg-light border">{{ $item->sla_snapshot['name'] ?? $item->slaPolicy?->name ?? 'Custom SLA' }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $item->quantity }} {{ $item->unit }}</td>
                                    <td>{{ ucfirst((string) $item->billing_interval) }}</td>
                                    <td class="text-end">{{ number_format((float) $item->unit_price, 2, ',', ' ') }} kr</td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $item->line_total, 2, ',', ' ') }} kr</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No service lines are visible on this contract.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="5" class="text-end">Monthly total</th>
                                <th class="text-end">{{ number_format((float) $contract->total_monthly_amount, 2, ',', ' ') }} kr</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            @foreach([
                'General terms' => $contract->terms_snapshot,
                'Data processing agreement' => $contract->dpa_snapshot,
                'Legal and GDPR' => $contract->legal_snapshot,
                'SLA terms' => $contract->sla_snapshot,
                'General comments' => $contract->general_snapshot,
            ] as $title => $body)
                @if(filled($body))
                    <div class="card shadow-sm mt-3">
                        <div class="card-header bg-body">
                            <h2 class="h6 mb-0">{{ $title }}</h2>
                        </div>
                        <div class="card-body">
                            <div class="small" style="white-space: pre-wrap;">{{ $body }}</div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header bg-body">
                    <h2 class="h6 mb-0">Contract details</h2>
                </div>
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Status</dt>
                        <dd class="col-7"><span class="badge text-bg-light border">{{ $access->statusLabel($contract) }}</span></dd>

                        <dt class="col-5 text-muted">Start</dt>
                        <dd class="col-7">{{ $contract->start_date?->format('Y-m-d') ?: '-' }}</dd>

                        <dt class="col-5 text-muted">End</dt>
                        <dd class="col-7">{{ $contract->end_date?->format('Y-m-d') ?: 'Ongoing' }}</dd>

                        <dt class="col-5 text-muted">SLA</dt>
                        <dd class="col-7">{{ $contract->sla?->name ?: 'Contract default' }}</dd>

                        @if($contract->accepted_at)
                            <dt class="col-5 text-muted">Accepted</dt>
                            <dd class="col-7">{{ $contract->accepted_at->format('Y-m-d H:i') }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            @if($contract->approval_status === 'won')
                <div class="alert alert-success mt-3">
                    <div class="fw-semibold">Contract accepted</div>
                    <div class="small">Accepted by {{ $contract->accepted_by_name }} {{ $contract->accepted_at?->format('Y-m-d H:i') }}</div>
                </div>
            @elseif($access->canAccept($contract))
                <div class="card shadow-sm mt-3">
                    <div class="card-header bg-body">
                        <h2 class="h6 mb-0">Accept contract</h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('customer-portal.contracts.accept', $contract) }}">
                            @csrf
                            <label for="contract_accept_name" class="form-label small">Name</label>
                            <input id="contract_accept_name" type="text" name="name" class="form-control form-control-sm mb-2" value="{{ old('name', $context->contact->display_name) }}" required>
                            <div class="form-check mb-3">
                                <input type="checkbox" name="confirm" value="1" id="contract_confirm" class="form-check-input" required>
                                <label for="contract_confirm" class="form-check-label small">I have read and accept this contract.</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
                                Accept contract
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
