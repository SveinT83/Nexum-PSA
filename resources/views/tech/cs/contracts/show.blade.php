@extends('layouts.default_tech')

@section('title', 'Contracts')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center py-3">
        <div>
            <h2 class="h4 mb-0">Contract #{{ $contract->id }} - Preview</h2>
            <p class="text-muted mb-0 small">Client: <strong>{{ $client->name }}</strong> ({{ $client->client_number }})</p>
        </div>
        <div>
            <x-buttons.back url="{{ route('tech.contracts.index') }}"> Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-9">
            {{--
                Validation Alert Section
                Shows a summary of why a contract is not yet "ready" for approval.
                Conditions checked: items presence, terms snapshot status, and start date.
            --}}
            @if(!$validation['ready'])
                <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4">
                    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                    <div>
                        <h4 class="h6 mb-1">Contract not ready for approval</h4>
                        <ul class="mb-0 small">
                            @if(!$validation['has_items']) <li>Missing services/items.</li> @endif
                            @if(!$validation['has_terms'])
                                <li>Terms snapshot has not been generated.
                                    <a href="{{ route('tech.contracts.terms', $contract) }}" class="alert-link">Edit Terms</a>
                                </li>
                            @endif
                            @if($validation['has_missing_terms'] && $validation['has_terms'])
                                <li>New services have been added, but terms have not been refreshed.
                                    <a href="{{ route('tech.contracts.terms', $contract) }}" class="alert-link">Refresh Terms</a>
                                </li>
                            @endif
                            @if(!$validation['future_start_date']) <li>Start date must be in the future.</li> @endif
                        </ul>
                    </div>
                </div>
            @else
                <div class="alert alert-success border-0 shadow-sm d-flex align-items-center mb-4">
                    <i class="bi bi-check-circle-fill fs-4 me-3"></i>
                    <div>
                        <h4 class="h6 mb-1">Contract is ready</h4>
                        <p class="mb-0 small">All requirements met. You can now approve or download the documents.</p>
                    </div>
                </div>
            @endif

            {{--
                Included Services Summary
                A detailed list of all line items currently attached to the contract.
                Shows quantity, billing interval, unit price, and calculated line totals.
            --}}
            <x-card.default title="Included Services">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Service</th>
                                <th>Quantity</th>
                                <th>Interval</th>
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
                                    <td>{{ $item->quantity }} {{ $item->unit }}</td>
                                    <td><span class="badge bg-light text-dark">{{ ucfirst($item->billing_interval) }}</span></td>
                                    <td class="text-end">{{ number_format($item->unit_price, 2, ',', ' ') }} kr</td>
                                    <td class="text-end fw-bold">{{ number_format($item->line_total, 2, ',', ' ') }} kr</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="4" class="text-end">Monthly Total:</th>
                                <th class="text-end">{{ number_format($contract->total_monthly_amount, 2, ',', ' ') }} kr</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-card.default>

            {{--
                Document Previews (Tabbed Interface)
                Displays the snapshotted legal text for various document types.
                Tabs include: General Terms, DPA, Legal/GDPR, SLA, and General comments.
            --}}
            <ul class="nav nav-tabs mt-4" id="contractTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="terms-tab" data-bs-toggle="tab" data-bs-target="#terms-pane" type="button" role="tab">General Terms</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="dpa-tab" data-bs-toggle="tab" data-bs-target="#dpa-pane" type="button" role="tab">DPA</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="legal-tab" data-bs-toggle="tab" data-bs-target="#legal-pane" type="button" role="tab">Legal & GDPR</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sla-tab" data-bs-toggle="tab" data-bs-target="#sla-pane" type="button" role="tab">SLA</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="general-tab" data-bs-toggle="tab" data-bs-target="#general-pane" type="button" role="tab">General</button>
                </li>
            </ul>
            <div class="tab-content border border-top-0 p-4 bg-white shadow-sm mb-5" id="contractTabsContent">
                <div class="tab-pane fade show active" id="terms-pane" role="tabpanel">
                    <div class="pre-scrollable" style="white-space: pre-wrap; font-size: 0.9rem;">{{ $contract->terms_snapshot ?: 'No terms generated.' }}</div>
                </div>
                <div class="tab-pane fade" id="dpa-pane" role="tabpanel">
                    <div class="pre-scrollable" style="white-space: pre-wrap; font-size: 0.9rem;">{{ $contract->dpa_snapshot ?: 'No DPA content generated.' }}</div>
                </div>
                <div class="tab-pane fade" id="legal-pane" role="tabpanel">
                    <div class="pre-scrollable" style="white-space: pre-wrap; font-size: 0.9rem;">{{ $contract->legal_snapshot ?: 'No legal content generated.' }}</div>
                </div>
                <div class="tab-pane fade" id="sla-pane" role="tabpanel">
                    <div class="pre-scrollable" style="white-space: pre-wrap; font-size: 0.9rem;">{{ $contract->sla_snapshot ?: 'No SLA content generated.' }}</div>
                </div>
                <div class="tab-pane fade" id="general-pane" role="tabpanel">
                    <div class="pre-scrollable" style="white-space: pre-wrap; font-size: 0.9rem;">{{ $contract->general_snapshot ?: 'No general content generated.' }}</div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            {{--
                Action Sidebar
                Main controls for approving the contract or navigating back to editors.
                Approve and PDF buttons are disabled if the contract is not "ready".
            --}}
            @if($contract->approval_status === 'won')
                <div class="alert alert-success border-0 shadow-sm mb-4">
                    <h6 class="mb-1"><i class="bi bi-trophy-fill me-2"></i>Contract Won</h6>
                    <p class="small mb-0">Accepted by: <strong>{{ $contract->accepted_by_name }}</strong></p>
                    <p class="small mb-0">Date: {{ $contract->accepted_at->format('d.m.Y H:i') }}</p>
                </div>
            @endif

            <x-card.default title="Actions">
                <div class="d-grid gap-2">
                    <div class="mb-3">
                        <label for="cc_email" class="form-label small fw-bold">CC Email (Optional):</label>
                        <input type="email" name="cc_email" id="cc_email" class="form-control form-control-sm" placeholder="cc@example.com" value="{{ $contract->cc_email }}" form="sendQuoteForm">
                        <script>
                            document.getElementById('cc_email').addEventListener('input', function() {
                                document.querySelectorAll('.cc-input-sync').forEach(el => el.value = this.value);
                            });
                        </script>
                    </div>

                    @if($contract->isEditable())
                        <form action="{{ route('tech.contracts.send-quote', $contract) }}" method="POST" id="sendQuoteForm">
                            @csrf
                            <input type="hidden" name="cc_email" class="cc-input-sync" value="{{ $contract->cc_email }}">
                            <button type="submit" class="btn btn-outline-primary w-100" @if(!$validation['ready']) disabled @endif>
                                <i class="bi bi-send me-2"></i> Send Quote
                            </button>
                        </form>
                        <form action="{{ route('tech.contracts.send-contract', $contract) }}" method="POST" id="sendContractForm">
                            @csrf
                            <input type="hidden" name="cc_email" class="cc-input-sync" value="{{ $contract->cc_email }}">
                            <button type="submit" class="btn btn-primary w-100" @if(!$validation['ready']) disabled @endif>
                                <i class="bi bi-send-check me-2"></i> Send Contract
                            </button>
                        </form>
                    @endif

                    @if(in_array($contract->approval_status, ['sent_quote', 'sent_contract']))
                        <div class="alert alert-info py-2 small mb-2">
                            <i class="bi bi-link-45deg me-1"></i>
                            <a href="{{ route('contracts.public.view', $contract->secure_token) }}" target="_blank" class="alert-link">Public Link</a>
                        </div>
                        <form action="{{ route('tech.contracts.resend', $contract) }}" method="POST" id="resendForm">
                            @csrf
                            <input type="hidden" name="cc_email" class="cc-input-sync" value="{{ $contract->cc_email }}">
                            <button type="submit" class="btn btn-outline-info w-100 mb-2">
                                <i class="bi bi-reply-all me-2"></i> Resend Email
                            </button>
                        </form>
                    @endif

                    @if($contract->approval_status !== 'won')
                        <form action="{{ route('tech.contracts.approve-manual', $contract) }}" method="POST" onsubmit="return confirm('Are you sure you want to manually approve this contract?')">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-check2-all me-2"></i> Manual Approve
                            </button>
                        </form>
                    @endif

                    <button class="btn btn-outline-dark" @if(!$validation['ready']) disabled @endif>
                        <i class="bi bi-file-earmark-pdf me-2"></i> Download PDF
                    </button>
                    <hr>
                    @if($contract->isEditable())
                        <a href="{{ route('tech.contracts.edit', $contract) }}" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-pencil me-2"></i> Edit Details
                        </a>
                        <a href="{{ route('tech.contracts.services.edit', $contract) }}" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-list-check me-2"></i> Edit Services
                        </a>
                        <a href="{{ route('tech.contracts.terms', $contract) }}" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-file-text me-2"></i> Edit Terms
                        </a>
                    @endif
                </div>
            </x-card.default>

            {{--
                Contract Meta Info
                Secondary information about the contract status and critical dates.
            --}}
            <x-card.default title="Contract Info">
                <div class="small">
                    <div class="mb-2">
                        <span class="text-muted d-block small uppercase font-weight-bold mb-1">Status:</span>
                        @php
                            $statusClass = match($contract->approval_status) {
                                'draft' => 'bg-secondary',
                                'negotiation' => 'bg-info text-dark',
                                'sent_quote', 'sent_contract' => 'bg-primary',
                                'won' => 'bg-success',
                                'lost', 'quote_lost' => 'bg-danger',
                                default => 'bg-secondary'
                            };
                        @endphp
                        <span class="badge {{ $statusClass }}">{{ ucfirst(str_replace('_', ' ', $contract->approval_status)) }}</span>
                    </div>
                    <div class="mb-2">
                        <span class="text-muted d-block small uppercase font-weight-bold mb-1">Start Date:</span>
                        <span class="{{ $validation['future_start_date'] ? 'text-success' : 'text-danger fw-bold' }}">
                            {{ $contract->start_date ? $contract->start_date->format('d.m.Y') : 'Not set' }}
                        </span>
                    </div>
                </div>
            </x-card.default>
        </div>
    </div>
@endsection

@section('sidebar')
@endsection

@section('rightbar')
@endsection
