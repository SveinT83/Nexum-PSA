<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract #{{ $contract->id }} - {{ $contract->client->name }}</title>
    @PwaHead
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Inter', sans-serif; }
        .contract-container { max-width: 900px; margin: 40px auto; background: white; padding: 60px; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15); border-radius: 8px; }
        .document-header { border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 40px; }
        .section-title { border-bottom: 1px solid #dee2e6; padding-bottom: 8px; margin-top: 30px; margin-bottom: 15px; font-weight: bold; text-transform: uppercase; font-size: 0.9rem; color: #6c757d; }
        .pre-wrap { white-space: pre-wrap; font-size: 0.95rem; line-height: 1.6; color: #333; }
        @media print {
            body { background: white; }
            .contract-container { margin: 0; padding: 0; box-shadow: none; max-width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="container pb-5">
    <div class="contract-container">
        <!-- Document Header -->
        <div class="document-header d-flex justify-content-between align-items-end">
            <div>
                <h1 class="h3 mb-1">CONTRACT / QUOTE</h1>
                <p class="text-muted mb-0 small">Reference: #{{ $contract->id }}</p>
            </div>
            <div class="text-end">
                <h2 class="h5 mb-1">{{ $contract->client->name }}</h2>
                <p class="text-muted mb-0 small">Client ID: {{ $contract->client->client_number }}</p>
            </div>
        </div>

        <!-- Success/Error Alerts -->
        @if(session('success'))
            <div class="alert alert-success border-0 shadow-sm mb-4">
                <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger border-0 shadow-sm mb-4">
                <i class="bi bi-exclamation-octagon-fill me-2"></i> {{ session('error') }}
            </div>
        @endif

        <!-- Contract Details -->
        <div class="row mb-4">
            <div class="col-6">
                <div class="section-title">Contract Dates</div>
                <table class="table table-sm table-borderless small">
                    <tr><td class="text-muted w-50">Start Date:</td><td><strong>{{ $contract->start_date?->format('d.m.Y') ?? 'TBA' }}</strong></td></tr>
                    @if($contract->end_date)
                    <tr><td class="text-muted">End Date:</td><td><strong>{{ $contract->end_date->format('d.m.Y') }}</strong></td></tr>
                    @endif
                    @if($contract->binding_end_date)
                    <tr><td class="text-muted">Binding Until:</td><td><strong>{{ $contract->binding_end_date->format('d.m.Y') }}</strong></td></tr>
                    @endif
                </table>
            </div>
            <div class="col-6 text-end">
                <div class="section-title">Status</div>
                <div class="h4">
                    @if($contract->approval_status === 'won')
                        <span class="badge bg-success">Accepted & Won</span>
                    @elseif($contract->approval_status === 'sent_quote')
                        <span class="badge bg-primary">Quote Pending</span>
                    @elseif($contract->approval_status === 'sent_contract')
                        <span class="badge bg-primary">Contract Pending</span>
                    @else
                        <span class="badge bg-secondary">{{ ucfirst($contract->approval_status) }}</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Services -->
        <div class="section-title">Included Services</div>
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>Description</th>
                        <th>SLA</th>
                        <th>Rates</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contract->items as $item)
                    <tr>
                        <td>
                            <div class="fw-bold">{{ $item->name }}</div>
                            <small class="text-muted">{{ $item->sku }}</small>
                        </td>
                        <td>
                            @if($item->uses_contract_default_sla)
                                <span class="badge text-bg-light border">Contract default</span>
                                <div class="small text-muted">{{ $contract->sla?->name ?? 'System default' }}</div>
                            @else
                                <span class="badge text-bg-primary">{{ $item->sla_snapshot['name'] ?? $item->slaPolicy?->name ?? 'Custom SLA' }}</span>
                            @endif
                        </td>
                        <td>
                            @forelse($item->timeRates->where('is_active', true) as $rate)
                                <div class="small">
                                    <span class="fw-semibold">{{ $rate->name }}</span>
                                    <span class="text-muted">{{ number_format((float) $rate->amount_ex_vat, 2, ',', ' ') }} {{ $rate->currency }}/{{ $rate->unit }}</span>
                                </div>
                            @empty
                                <span class="text-muted small">No rates</span>
                            @endforelse
                        </td>
                        <td class="text-center">{{ (int)$item->quantity }} {{ $item->unit }}</td>
                        <td class="text-end">{{ number_format($item->unit_price, 2, ',', ' ') }} kr</td>
                        <td class="text-end fw-bold">{{ number_format($item->line_total, 2, ',', ' ') }} kr</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-light">
                    <tr>
                        <th colspan="5" class="text-end">Recurring Monthly Amount (ex VAT):</th>
                        <th class="text-end h5 mb-0">{{ number_format($contract->total_monthly_amount, 2, ',', ' ') }} kr</th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Terms -->
        <div class="section-title">Terms & Conditions</div>
        <div class="pre-wrap mb-4">{{ $contract->terms_snapshot }}</div>

        @if($contract->dpa_snapshot)
        <div class="section-title">Data Processing Agreement (DPA)</div>
        <div class="pre-wrap mb-4">{{ $contract->dpa_snapshot }}</div>
        @endif

        <!-- Acceptance Section -->
        <div class="no-print mt-5 pt-4 border-top">
            @if($contract->approval_status === 'won')
                <div class="text-center py-4 bg-light rounded">
                    <h3 class="h5 text-success mb-1"><i class="bi bi-check2-circle me-2"></i>Contract Accepted</h3>
                    <p class="text-muted mb-0 small">Accepted by {{ $contract->accepted_by_name }} on {{ $contract->accepted_at->format('d.m.Y H:i') }}</p>
                    <p class="text-muted mb-0 small">IP: {{ $contract->accepted_ip }}</p>
                </div>
            @elseif(in_array($contract->approval_status, ['sent_quote', 'sent_contract']))
                <div class="card border-primary shadow-sm">
                    <div class="card-body p-4">
                        <h3 class="h5 mb-3">Acceptance</h3>
                        <form action="{{ route('contracts.public.accept', $contract->secure_token) }}" method="POST">
                            @csrf
                            <div class="mb-3">
                                <label for="name" class="form-label fw-bold">Full Name:</label>
                                <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required placeholder="Enter your full name">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-3 form-check">
                                <input type="checkbox" name="confirm" id="confirm" class="form-check-input @error('confirm') is-invalid @enderror" required>
                                <label class="form-check-label" for="confirm">
                                    I confirm that I have read and accept the terms and conditions of this contract.
                                </label>
                                @error('confirm') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-check2-circle me-2"></i> Accept Contract
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </div>

        <div class="mt-5 text-center small text-muted">
            <p>© {{ date('Y') }} {{ $companyProfile['company_name'] ?? config('app.name', 'Nexum PSA') }} - Generated on {{ now()->format('d.m.Y H:i') }}</p>
            <button onclick="window.print()" class="btn btn-sm btn-link no-print"><i class="bi bi-printer me-1"></i> Print / Save as PDF</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
@RegisterServiceWorkerScript
</body>
</html>
