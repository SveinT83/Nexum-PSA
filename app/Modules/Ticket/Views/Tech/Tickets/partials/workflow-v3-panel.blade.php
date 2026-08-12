@php
    $currentQuote = $ticket->salesContext?->opportunity?->currentQuoteVersion;
    $currentOpportunity = $ticket->salesContext?->opportunity;
    $plannedDecision = $actionDecision('add_planned_cost');
    $createQuoteDecision = $actionDecision('create_quote');
    $sendQuoteDecision = $actionDecision('send_quote');
    $voidQuoteDecision = $actionDecision('void_accepted_quote');
    $reviewRequestDecision = $actionDecision('request_senior_review');
    $reviewDecision = $actionDecision('senior_review');
    $evidenceDecision = $actionDecision('classify_evidence');
    $closeDecision = $actionDecision('close');
    $inboundCustomerMessages = $ticket->messages->filter(fn ($message) => $message->author_type !== 'user')->sortByDesc('created_at');
    $activeWorkflowEvidence = $ticket->workflowEvidence->whereNull('invalidated_at');
    $showSalesApprovalPanel = (bool) $currentOpportunity || (bool) $currentQuote || $ticket->plannedLines->isNotEmpty();
    $showReviewEvidencePanel = $ticket->workflowReviews->isNotEmpty()
        || $activeWorkflowEvidence->isNotEmpty()
        || in_array($reviewRequestDecision['mode'] ?? 'inherit', ['conditional', 'blocked'], true)
        || in_array($evidenceDecision['mode'] ?? 'inherit', ['conditional', 'blocked'], true);
    $quotePanelPlacement = $quotePanelPlacement ?? 'primary';
    $deferAcceptedQuotePanel = (bool) ($deferAcceptedQuotePanel ?? false);
    $renderQuotePanel = $showSalesApprovalPanel
        && (
            ($quotePanelPlacement === 'primary' && ! $deferAcceptedQuotePanel)
            || ($quotePanelPlacement === 'deferred' && $deferAcceptedQuotePanel)
        );
    $renderReviewEvidencePanel = $quotePanelPlacement === 'primary' && $showReviewEvidencePanel;
    $isAdditionalQuote = data_get($currentQuote?->snapshots, 'revision_mode') === 'additional_after_acceptance';
    $quotePanelTitle = $currentQuote?->status === 'accepted'
        ? 'Accepted quote and delivery'
        : ($isAdditionalQuote ? 'Additional customer approval' : 'Quote and customer approval');
    $quotePanelDescription = $currentQuote?->status === 'accepted'
        ? 'Customer approval is recorded. Accepted lines are processed automatically; remaining buttons are retry actions.'
        : ($isAdditionalQuote
            ? 'Send only the new scope that was discovered after the earlier accepted quote.'
            : 'Build the quote, send it for approval, and keep fulfilment blocked until acceptance.');
    $quoteScopeNote = $currentQuote?->status === 'accepted'
        ? 'Accepted quote lines become reservation, draft purchase need, or pending actual cost. Picking, vendor orders, and billing stay separate.'
        : ($isAdditionalQuote
            ? 'The earlier accepted quote is preserved as audit history. These new lines need their own customer acceptance before delivery.'
            : 'Quote-scope lines do not reserve stock or create billing until the customer has accepted the current quote.');
    $voidQuoteModalId = $currentQuote ? 'ticketVoidAcceptedQuoteModal'.$currentQuote->id : null;
@endphp

<!-- Commercial approval appears only after a Ticket has actual quote scope or a linked Sales context. -->
@if($renderQuotePanel)
<div class="card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h2 class="h6 mb-1">{{ $quotePanelTitle }}</h2>
            <div class="small text-muted">{{ $quotePanelDescription }}</div>
        </div>
        @if($plannedDecision['visible'])
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#ticketPlannedLineModal" @disabled(! $plannedDecision['allowed']) title="{{ $plannedDecision['reason'] }}">
                Add quote line
            </button>
        @endif
    </div>
    <div class="card-body">
        <p class="small text-muted">{{ $quoteScopeNote }}</p>

                <div class="table-responsive border rounded mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Line</th><th>Status</th><th class="text-end">Ex VAT</th><th></th></tr></thead>
                        <tbody>
                        @forelse($ticket->plannedLines as $line)
                            @php
                                $lineProcessed = (bool) $line->converted_cost_entry_id || (bool) $line->purchaseOrderLine;
                                $lineStatusLabel = $line->converted_cost_entry_id
                                    ? 'Actual cost'
                                    : ($line->purchaseOrderLine ? 'Purchase need' : ucfirst($line->status));
                                $lineStatusClass = $lineProcessed ? 'text-bg-success' : 'text-bg-light border';
                            @endphp
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $line->name }}</span>
                                    <div class="small text-muted">{{ $line->quantity }} {{ $line->unit }}{{ $line->sku ? ' - '.$line->sku : '' }}</div>
                                </td>
                                <td><span class="badge {{ $lineStatusClass }}">{{ $lineStatusLabel }}</span></td>
                                <td class="text-end">{{ number_format((float) $line->quantity * (float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        @if(in_array($line->status, ['planned', 'quoted'], true) && $plannedDecision['visible'])
                                            <form method="POST" action="{{ route('tech.tickets.planned-lines.destroy', [$ticket, $line]) }}">
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" @disabled(! $plannedDecision['allowed'])>Remove</button>
                                            </form>
                                        @endif
                                        @if($line->status === 'approved' && ! $lineProcessed && $actionDecision($line->storage_item_id ? 'reserve_item' : 'add_actual_cost')['visible'])
                                            <form method="POST" action="{{ route('tech.tickets.planned-lines.convert', [$ticket, $line]) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-success" @disabled(! $actionDecision($line->storage_item_id ? 'reserve_item' : 'add_actual_cost')['allowed'])>Retry delivery</button>
                                            </form>
                                        @endif
                                        @if($line->status === 'approved' && ! $lineProcessed && $line->storageItem?->can_be_ordered && $actionDecision('request_purchase')['visible'])
                                            <form method="POST" action="{{ route('tech.tickets.planned-lines.purchase', [$ticket, $line]) }}">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-warning" @disabled(! $actionDecision('request_purchase')['allowed'])>Retry purchase</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted p-3">No quote lines yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border rounded p-3">
                    @if(! $currentQuote)
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <div class="small"><strong>No quote yet.</strong><br><span class="text-muted">A linked service Opportunity is created automatically.</span></div>
                            @if($createQuoteDecision['visible'])
                                <form method="POST" action="{{ route('tech.tickets.sales-quote.create', $ticket) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-primary" @disabled(! $createQuoteDecision['allowed']) title="{{ $createQuoteDecision['reason'] }}">Create Quote</button>
                                </form>
                            @endif
                        </div>
                    @else
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div class="small">
                                <strong>{{ $currentQuote->quote?->quote_key }} v{{ $currentQuote->version_number }}</strong>
                                <span class="badge {{ $currentQuote->status === 'accepted' ? 'text-bg-success' : 'text-bg-light border' }} ms-1">{{ ucfirst($currentQuote->status) }}</span>
                                <div class="text-muted mt-1">{{ number_format((float) $currentQuote->total_ex_vat, 2, ',', ' ') }} NOK ex VAT</div>
                            </div>
                            <div class="d-flex gap-1">
                                @if($currentQuote->status === 'draft' && $createQuoteDecision['visible'])
                                    <form method="POST" action="{{ route('tech.tickets.sales-quote.create', $ticket) }}">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" @disabled(! $createQuoteDecision['allowed']) title="Import planned lines that are not yet in this draft">Sync Ticket scope</button>
                                    </form>
                                @endif
                                @if($currentOpportunity)
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('tech.sales.show', $currentOpportunity) }}?open_quote=1" target="_blank">Open Sales quote editor</a>
                                @endif
                                @if($currentQuote->status === 'draft' && $sendQuoteDecision['visible'])
                                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#ticketSendQuoteForm" @disabled(! $sendQuoteDecision['allowed']) title="{{ $sendQuoteDecision['reason'] }}">Send for approval</button>
                                @endif
                                @if($currentQuote->status === 'accepted' && $voidQuoteDecision['visible'])
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        data-bs-toggle="modal"
                                        data-bs-target="#{{ $voidQuoteModalId }}"
                                        @disabled(! $voidQuoteDecision['allowed'])
                                        title="{{ $voidQuoteDecision['reason'] }}">
                                        Void
                                    </button>
                                @endif
                            </div>
                        </div>
                        @if($currentQuote->status === 'draft')
                            <div class="collapse mt-3" id="ticketSendQuoteForm">
                                <form method="POST" action="{{ route('tech.tickets.sales-quote.send', $ticket) }}">
                                    @csrf
                                    <label class="form-label small">Ticket reply text</label>
                                    <textarea name="body" class="form-control form-control-sm mb-2" rows="3" placeholder="Please review the attached quote and use the link to accept it."></textarea>
                                    <button class="btn btn-sm btn-primary">Send Ticket reply, PDF and acceptance link</button>
                                </form>
                            </div>
                        @endif
                    @endif
                </div>

    </div>
</div>
@endif

@if($renderReviewEvidencePanel)
<!-- Review and evidence remain task-focused tools outside the commercial approval surface. -->
<div class="card mb-3">
    <div class="card-header">
        <h2 class="h6 mb-1">Review and evidence</h2>
        <div class="small text-muted">Record senior checks and customer evidence when the workflow requires it.</div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-xl-6">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                    <h3 class="h6 mb-0">Senior review</h3>
                    @if($reviewRequestDecision['visible'])
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#ticketSeniorReviewRequest" @disabled(! $reviewRequestDecision['allowed']) title="{{ $reviewRequestDecision['reason'] }}">Request review</button>
                    @endif
                </div>
                <div class="collapse mb-2" id="ticketSeniorReviewRequest">
                    <form method="POST" action="{{ route('tech.tickets.workflow-reviews.store', $ticket) }}" class="border rounded p-2">
                        @csrf
                        <input name="gate_key" class="form-control form-control-sm mb-2" value="senior-review" placeholder="Review gate">
                        <select name="assigned_reviewer_id" class="form-select form-select-sm mb-2">
                            <option value="">Any eligible senior</option>
                            @foreach($seniorReviewers as $reviewer)
                                <option value="{{ $reviewer->id }}">{{ $reviewer->name }}</option>
                            @endforeach
                        </select>
                        <textarea name="comment" class="form-control form-control-sm mb-2" rows="2" placeholder="What should be checked?"></textarea>
                        <input type="hidden" name="separation_of_duties" value="1">
                        <button class="btn btn-sm btn-primary">Request senior review</button>
                    </form>
                </div>
                @forelse($ticket->workflowReviews->sortByDesc('created_at')->take(5) as $review)
                    <div class="border rounded p-2 mb-2 small">
                        <div class="d-flex justify-content-between gap-2">
                            <strong>{{ $review->gate_key }}</strong>
                            <span class="badge text-bg-light border">{{ ucfirst(str_replace('_', ' ', $review->status)) }}</span>
                        </div>
                        <div class="text-muted">Requested by {{ $review->requester?->name ?? 'Unknown' }}{{ $review->assignedReviewer ? ' - assigned to '.$review->assignedReviewer->name : '' }}</div>
                        @if($review->comment)<div class="mt-1">{{ $review->comment }}</div>@endif
                        @if($review->status === 'pending' && $reviewDecision['visible'])
                            <form method="POST" action="{{ route('tech.tickets.workflow-reviews.decide', [$ticket, $review]) }}" class="mt-2">
                                @csrf
                                <textarea name="comment" class="form-control form-control-sm mb-1" rows="2" placeholder="Comment (required when sent back)"></textarea>
                                <div class="d-flex gap-1">
                                    <button name="decision" value="approved" class="btn btn-sm btn-outline-success" @disabled(! $reviewDecision['allowed'])>Approve</button>
                                    <button name="decision" value="sent_back" class="btn btn-sm btn-outline-warning" @disabled(! $reviewDecision['allowed'])>Send back</button>
                                </div>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="small text-muted mb-0">No review checkpoints yet.</p>
                @endforelse
            </div>

            <div class="col-xl-6">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                    <h3 class="h6 mb-0">Customer evidence</h3>
                    @if($evidenceDecision['visible'])
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#ticketEvidenceForm" @disabled(! $evidenceDecision['allowed']) title="{{ $evidenceDecision['reason'] }}">Classify evidence</button>
                    @endif
                </div>
                <div class="collapse mb-2" id="ticketEvidenceForm">
                    <form method="POST" action="{{ route('tech.tickets.workflow-evidence.store', $ticket) }}" class="border rounded p-2">
                        @csrf
                        <select name="evidence_type" class="form-select form-select-sm mb-2" required>
                            <option value="customer_response">Customer written response</option>
                            <option value="signature">Uploaded signature</option>
                            <option value="manual_approval">Manual approval evidence</option>
                        </select>
                        <select name="source_type" class="form-select form-select-sm mb-2" required>
                            <option value="message">Ticket message</option>
                            <option value="attachment">Ticket attachment</option>
                        </select>
                        <select name="source_id" class="form-select form-select-sm mb-2" required>
                            <optgroup label="Customer messages">
                                @foreach($inboundCustomerMessages as $message)
                                    <option value="{{ $message->id }}">#{{ $message->id }} - {{ \Illuminate\Support\Str::limit($message->body, 80) }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="Attachments">
                                @foreach($ticket->attachments as $attachment)
                                    <option value="{{ $attachment->id }}">#{{ $attachment->id }} - {{ $attachment->filename }}</option>
                                @endforeach
                            </optgroup>
                        </select>
                        <input name="scope_key" class="form-control form-control-sm mb-2" placeholder="Applies to request/quote (optional)">
                        <textarea name="comment" class="form-control form-control-sm mb-2" rows="2" placeholder="Why is this valid evidence?"></textarea>
                        <button class="btn btn-sm btn-primary">Save evidence classification</button>
                    </form>
                </div>
                @forelse($activeWorkflowEvidence->sortByDesc('evidenced_at')->take(6) as $evidence)
                    <div class="d-flex justify-content-between gap-2 border-bottom py-2 small">
                        <div><strong>{{ ucfirst(str_replace('_', ' ', $evidence->evidence_type)) }}</strong><div class="text-muted">{{ $evidence->subject_name ?: $evidence->scope_key ?: 'Ticket evidence' }}</div></div>
                        <div class="text-end text-muted">{{ $evidence->evidenced_at?->format('Y-m-d H:i') }}<br>{{ $evidence->creator?->name }}</div>
                    </div>
                @empty
                    <p class="small text-muted mb-0">No classified workflow evidence yet.</p>
                @endforelse

                @if($currentQuote?->status === 'sent' && $inboundCustomerMessages->isNotEmpty() && $actionDecision('mark_quote_acceptance')['visible'])
                    <form method="POST" action="{{ route('tech.tickets.sales-quote.accept-message', [$ticket, $inboundCustomerMessages->first(), $currentQuote]) }}" class="border rounded p-2 mt-3">
                        @csrf
                        <div class="small fw-semibold mb-2">Customer accepted by email?</div>
                        <input name="name" class="form-control form-control-sm mb-2" value="{{ $ticket->contact?->name }}" placeholder="Customer name" required>
                        <textarea name="comment" class="form-control form-control-sm mb-2" rows="2" placeholder="Confirm what the customer wrote in message #{{ $inboundCustomerMessages->first()->id }}"></textarea>
                        <button class="btn btn-sm btn-outline-success" @disabled(! $actionDecision('mark_quote_acceptance')['allowed'])>Mark latest customer reply as acceptance</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endif

@if($renderQuotePanel && $plannedDecision['visible'])
    <div class="modal fade" id="ticketPlannedLineModal" tabindex="-1" aria-labelledby="ticketPlannedLineModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header"><h2 class="modal-title h5" id="ticketPlannedLineModalLabel">Add quote line</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form method="POST" action="{{ route('tech.tickets.planned-lines.store', $ticket) }}">
                    @csrf
                    <div class="modal-body">
                        <div class="alert alert-info small">This describes what the customer may approve. It does not reserve stock, order anything, or create an invoice line.</div>
                        <div class="row g-3">
                            <div class="col-12"><label class="form-label">Storage item (optional)</label><select name="storage_item_id" class="form-select"><option value="">Custom line / estimated work</option>@foreach($storageItems as $item)<option value="{{ $item['id'] }}">{{ $item['label'] }} - {{ number_format((float) $item['sale_price'], 2, ',', ' ') }} NOK</option>@endforeach</select></div>
                            <div class="col-md-8"><label class="form-label">Name</label><input name="name" class="form-control" placeholder="Mesh router or configuration work"></div>
                            <div class="col-md-4"><label class="form-label">Section</label><select name="section" class="form-select"><option value="equipment">Equipment</option><option value="implementation">Implementation</option><option value="one_time_costs">One-time costs</option></select></div>
                            <div class="col-md-4"><label class="form-label">Quantity</label><input name="quantity" type="number" step="0.01" min="0.01" value="1" class="form-control" required></div>
                            <div class="col-md-4"><label class="form-label">Price ex VAT</label><input name="unit_price_ex_vat" type="number" step="0.01" min="0" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label">VAT %</label><input name="vat_rate" type="number" step="0.01" min="0" max="100" value="25" class="form-control"></div>
                            <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add quote line</button></div>
                </form>
            </div>
        </div>
    </div>
@endif

@if($renderQuotePanel && $currentQuote?->status === 'accepted' && $voidQuoteDecision['visible'])
    <div class="modal fade" id="{{ $voidQuoteModalId }}" tabindex="-1" aria-labelledby="{{ $voidQuoteModalId }}Label" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h2 class="modal-title h5" id="{{ $voidQuoteModalId }}Label">Void accepted quote</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form method="POST" action="{{ route('tech.tickets.sales-quote.void', [$ticket, $currentQuote]) }}">
                    @csrf
                    <div class="modal-body">
                        <div class="alert alert-warning small">This keeps the original customer acceptance for audit, but removes it as approved delivery scope when no irreversible work has happened.</div>
                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control" rows="3" required placeholder="What changed after the customer accepted this quote?"></textarea>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" @disabled(! $voidQuoteDecision['allowed'])>Void accepted quote</button></div>
                </form>
            </div>
        </div>
    </div>
@endif

@if(! $ticket->status?->is_closed && ($closeDecision['visible'] ?? false))
    <div class="modal fade" id="ticketCloseOutcomeModal" tabindex="-1" aria-labelledby="ticketCloseOutcomeModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h2 class="modal-title h5" id="ticketCloseOutcomeModalLabel">Close Ticket</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form method="POST" action="{{ route('tech.tickets.close', $ticket) }}">
                    @csrf
                    <div class="modal-body">
                        <label class="form-label">Outcome</label>
                        <select name="outcome" class="form-select mb-3" required>
                            <option value="completed">Completed - create normal Economy output</option>
                            <option value="customer_declined">Customer declined - no sale</option>
                            <option value="cancelled">Cancelled - no billing closure</option>
                            <option value="no_sale">No sale - no billing closure</option>
                        </select>
                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="Required for declined, cancelled, or no-sale outcomes"></textarea>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Close Ticket</button></div>
                </form>
            </div>
        </div>
    </div>
@endif
