@php
    $voidQuoteDecision = $voidQuoteDecision ?? ['visible' => false, 'allowed' => false, 'reason' => null];
@endphp

@if(($acceptedQuoteVersions ?? collect())->isNotEmpty())
    <!-- Accepted quote history stays below Activity so completed approvals remain visible without blocking daily ticket work. -->
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h6 mb-1">Accepted quote and delivery</h2>
            <div class="small text-muted">Previous customer approvals are preserved as audit history. New scope uses a separate additional quote.</div>
        </div>
        <div class="card-body">
            @foreach($acceptedQuoteVersions as $acceptedQuote)
                @php
                    $acceptedLines = $ticket->plannedLines
                        ->where('approved_quote_version_id', $acceptedQuote->id)
                        ->values();
                    $processedCount = $acceptedLines
                        ->filter(fn ($line): bool => (bool) $line->converted_cost_entry_id || (bool) $line->purchaseOrderLine)
                        ->count();
                    $voidModalId = 'ticketVoidAcceptedQuoteModal'.$acceptedQuote->id;
                @endphp
                <div class="border rounded p-3 {{ $loop->last ? '' : 'mb-3' }}">
                    <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                        <div>
                            <div class="fw-semibold">{{ $acceptedQuote->quote?->quote_key }} v{{ $acceptedQuote->version_number }}</div>
                            <div class="small text-muted">
                                Accepted by {{ $acceptedQuote->accepted_by_name ?: 'customer' }}
                                {{ $acceptedQuote->accepted_at ? $acceptedQuote->accepted_at->format('Y-m-d H:i') : '' }}
                                &middot; {{ number_format((float) $acceptedQuote->acceptedTotalExVat(), 2, ',', ' ') }} NOK ex VAT
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-1">
                            <span class="badge text-bg-success">Accepted</span>
                            @if($acceptedLines->isNotEmpty())
                                <span class="badge text-bg-light border">{{ $processedCount }}/{{ $acceptedLines->count() }} processed</span>
                            @endif
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('sales.quotes.public.pdf', $acceptedQuote->secure_token) }}" target="_blank">PDF</a>
                            @if($voidQuoteDecision['visible'])
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger"
                                    data-bs-toggle="modal"
                                    data-bs-target="#{{ $voidModalId }}"
                                    @disabled(! $voidQuoteDecision['allowed'])
                                    title="{{ $voidQuoteDecision['reason'] }}">
                                    Void
                                </button>
                            @endif
                        </div>
                    </div>

                    @if($acceptedLines->isNotEmpty())
                        <div class="table-responsive mt-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Line</th>
                                        <th>Status</th>
                                        <th class="text-end">Ex VAT</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($acceptedLines as $line)
                                        @php
                                            $lineStatusLabel = $line->converted_cost_entry_id
                                                ? 'Actual cost'
                                                : ($line->purchaseOrderLine ? 'Purchase need' : ucfirst($line->status));
                                        @endphp
                                        <tr>
                                            <td>
                                                <span class="fw-semibold">{{ $line->name }}</span>
                                                <div class="small text-muted">{{ $line->quantity }} {{ $line->unit }}{{ $line->sku ? ' - '.$line->sku : '' }}</div>
                                            </td>
                                            <td><span class="badge text-bg-light border">{{ $lineStatusLabel }}</span></td>
                                            <td class="text-end">{{ number_format((float) $line->quantity * (float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                @if($voidQuoteDecision['visible'])
                    <div class="modal fade" id="{{ $voidModalId }}" tabindex="-1" aria-labelledby="{{ $voidModalId }}Label" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h2 class="modal-title h5" id="{{ $voidModalId }}Label">Void accepted quote</h2>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <form method="POST" action="{{ route('tech.tickets.sales-quote.void', [$ticket, $acceptedQuote]) }}">
                                    @csrf
                                    <div class="modal-body">
                                        <div class="alert alert-warning small">
                                            This keeps the original customer acceptance for audit, but removes it as approved delivery scope when no irreversible work has happened.
                                        </div>
                                        <label class="form-label">Reason</label>
                                        <textarea name="reason" class="form-control" rows="3" required placeholder="What changed after the customer accepted this quote?"></textarea>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button class="btn btn-danger" @disabled(! $voidQuoteDecision['allowed'])>Void accepted quote</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@endif
