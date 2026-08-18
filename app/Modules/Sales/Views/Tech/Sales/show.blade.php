@extends('layouts.default_tech')

@section('title', $sale->opportunity_key)

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="mb-0">{{ $sale->title }}</h1>
            <p class="text-muted mb-0">{{ $sale->opportunity_key }} / {{ $sale->client?->name }}</p>
        </div>
        <div class="d-flex flex-wrap justify-content-end gap-2">
            @unless($sale->currentQuoteVersion)
                <form method="POST" action="{{ route('tech.sales.quote.ensure', $sale) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary"><i class="bi bi-file-earmark-text"></i> Prepare Quote</button>
                </form>
            @endunless
            @if($sale->status === 'lost')
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reopenOpportunityModal">
                    <i class="bi bi-arrow-counterclockwise"></i> Reopen
                </button>
            @elseif(! in_array($sale->status, ['won', 'not_qualified', 'no_quote_allowed'], true))
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#markLostModal">
                    <i class="bi bi-x-circle"></i> Mark as lost
                </button>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @if($sale->status === 'lost')
            {{-- Lost outcome remains visible while the opportunity history and quote records stay intact. --}}
            <div class="alert alert-warning d-flex flex-wrap justify-content-between gap-3" role="status">
                <div>
                    <div class="fw-semibold">Lost {{ $sale->lost_at?->format('d.m.Y H:i') }}</div>
                    <div style="white-space: pre-wrap;">{{ $sale->lost_reason ?: 'No loss reason was recorded for this legacy opportunity.' }}</div>
                </div>
                <div class="small text-muted">Reopening does not restore the previous follow-up.</div>
            </div>
        @endif

        {{-- Opportunity details and forecast are editable while the sales process is active. --}}
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="mb-0">Opportunity</h5>
                    <div class="small text-muted">
                        {{ $statuses[$sale->status]['label'] ?? $sale->status }} / {{ $sale->owner?->name ?? 'Unassigned' }} / {{ $sale->probability_percent }}%
                        @if($sale->primaryContact)
                            / {{ $sale->primaryContact->name }} &lt;{{ $sale->primaryContact->email }}&gt;
                        @else
                            / No sales contact
                        @endif
                    </div>
                </div>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#opportunityDetails" aria-expanded="false" aria-controls="opportunityDetails">
                    Edit
                </button>
            </div>
            <div class="collapse" id="opportunityDetails">
                <form method="POST" action="{{ route('tech.sales.update', $sale) }}">
                    @csrf
                    @method('PATCH')
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    @if($sale->status === 'lost')
                                        <option value="lost" selected>Lost</option>
                                    @else
                                        @foreach($statuses as $key => $status)
                                            @continue($key === 'lost')
                                            <option value="{{ $key }}" @selected($sale->status === $key)>{{ $status['label'] }}</option>
                                        @endforeach
                                    @endif
                                </select>
                                <div class="form-text">Use the dedicated lost or reopen action for those transitions.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Owner</label>
                                <select name="owner_id" class="form-select">
                                    @foreach($owners as $owner)
                                        <option value="{{ $owner->id }}" @selected($sale->owner_id === $owner->id)>{{ $owner->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <label for="primary_contact_id" class="form-label mb-0">Sales contact</label>
                                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-bs-toggle="modal" data-bs-target="#quickContactModal">
                                        New contact
                                    </button>
                                </div>
                                <select id="primary_contact_id" name="primary_contact_id" class="form-select">
                                    <option value="">No sales contact</option>
                                    @foreach($sale->client?->contacts ?? [] as $contact)
                                        @if($contact->active && $contact->email)
                                            <option value="{{ $contact->id }}" @selected($sale->primary_contact_id === $contact->id)>
                                                {{ $contact->name }}@if($contact->role) / {{ $contact->role }}@endif &lt;{{ $contact->email }}&gt;
                                            </option>
                                        @endif
                                    @endforeach
                                </select>
                                <div class="form-text">This contact receives quote email by default.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Probability %</label>
                                <input type="number" name="probability_percent" min="0" max="100" class="form-control" value="{{ $sale->probability_percent }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Estimated value ex VAT</label>
                                <input type="number" name="estimated_value_ex_vat" min="0" step="0.01" class="form-control" value="{{ $sale->estimated_value_ex_vat }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Expected close</label>
                                <input type="date" name="expected_close_date" class="form-control" value="{{ $sale->expected_close_date?->toDateString() }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Next follow-up</label>
                                <input type="datetime-local" name="next_follow_up_at" class="form-control" value="{{ $sale->next_follow_up_at?->format('Y-m-d\\TH:i') }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Employees</label>
                                <input type="number" name="employee_count_estimate" class="form-control" value="{{ $sale->employee_count_estimate }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Users</label>
                                <input type="number" name="user_count_estimate" class="form-control" value="{{ $sale->user_count_estimate }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Workstations</label>
                                <input type="number" name="workstation_count_estimate" class="form-control" value="{{ $sale->workstation_count_estimate }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Servers</label>
                                <input type="number" name="server_count_estimate" class="form-control" value="{{ $sale->server_count_estimate }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Next action</label>
                                <select name="next_follow_up_type" class="form-select">
                                    <option value="">No action selected</option>
                                    @if($sale->next_follow_up_type && ! array_key_exists($sale->next_follow_up_type, $nextActions))
                                        <option value="{{ $sale->next_follow_up_type }}" selected>{{ $sale->next_follow_up_type }}</option>
                                    @endif
                                    @foreach($nextActions as $key => $label)
                                        <option value="{{ $key }}" @selected($sale->next_follow_up_type === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Follow-up note</label>
                                <input type="text" name="next_follow_up_note" class="form-control" value="{{ $sale->next_follow_up_note }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Needs</label>
                                <textarea name="needs" class="form-control" rows="3">{{ $sale->needs }}</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-primary">Save Opportunity</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Dedicated outcome modals enforce complete, auditable Sales transitions. --}}
        @if($sale->status === 'lost')
            <div class="modal fade" id="reopenOpportunityModal" tabindex="-1" aria-labelledby="reopenOpportunityModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('tech.sales.reopen', $sale) }}" class="modal-content">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="reopenOpportunityModalLabel">Reopen opportunity</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="reopen_status" class="form-label">Active status</label>
                                <select id="reopen_status" name="status" class="form-select" required>
                                    @foreach($reopenStatuses as $statusKey)
                                        <option value="{{ $statusKey }}">{{ $statuses[$statusKey]['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <p class="text-muted small mb-0">
                                Probability is reset from the selected status. The previous follow-up is not restored.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Reopen opportunity</button>
                        </div>
                    </form>
                </div>
            </div>
        @elseif(! in_array($sale->status, ['won', 'not_qualified', 'no_quote_allowed'], true))
            <div class="modal fade" id="markLostModal" tabindex="-1" aria-labelledby="markLostModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('tech.sales.lost', $sale) }}" class="modal-content">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="markLostModalLabel">Mark opportunity as lost</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="lost_reason" class="form-label">Lost reason</label>
                                <textarea id="lost_reason" name="lost_reason" class="form-control" rows="3" maxlength="4000" required>{{ old('lost_reason') }}</textarea>
                            </div>
                            <div>
                                <label for="lost_internal_note" class="form-label">Internal note <span class="text-muted">(optional)</span></label>
                                <textarea id="lost_internal_note" name="internal_note" class="form-control" rows="3" maxlength="4000">{{ old('internal_note') }}</textarea>
                            </div>
                            <p class="text-muted small mt-3 mb-0">
                                Probability becomes 0%. The next follow-up is cleared, and its generated future calendar event is removed.
                            </p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Mark as lost</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Quick contact modal -->
        <!-- Allows the seller to add the real decision maker while editing the opportunity. -->
        <!-- ------------------------------------------------- -->
        <div class="modal fade" id="quickContactModal" tabindex="-1" aria-labelledby="quickContactModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form class="modal-content" id="quickContactForm" data-store-url="{{ route('tech.sales.clients.contacts.quick-store', $sale->client) }}">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="quickContactModalLabel">New Contact</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none" id="quickContactErrors"></div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="quick_contact_name" class="form-label">Name</label>
                                <input type="text" id="quick_contact_name" name="name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="quick_contact_email" class="form-label">Email</label>
                                <input type="email" id="quick_contact_email" name="email" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label for="quick_contact_phone" class="form-label">Phone</label>
                                <input type="tel" id="quick_contact_phone" name="phone" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label for="quick_contact_role" class="form-label">Role</label>
                                <select id="quick_contact_role" name="role" class="form-select">
                                    <option value="">Select role</option>
                                    @foreach($clientContactRoles as $role)
                                        <option value="{{ $role }}">{{ $role }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="quick_contact_site_id" class="form-label">Site</label>
                                <select id="quick_contact_site_id" name="client_site_id" class="form-select">
                                    <option value="">Default site</option>
                                    @foreach($sale->client?->sites ?? [] as $site)
                                        <option value="{{ $site->id }}">{{ $site->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="quickContactSubmit">Create Contact</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Quote builder stores structured commercial lines and a stable customer-facing version. --}}
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">Quote</h5>
                    @if($sale->currentQuoteVersion)
                        @php
                            $quoteSummaryVersion = $sale->currentQuoteVersion;
                            $quoteLineCount = $quoteSummaryVersion->lines->count();
                        @endphp
                        <div class="small text-muted">
                            {{ ucfirst($quoteSummaryVersion->status) }} / {{ $quoteSummaryVersion->lines->count() }} lines / Approval {{ str_replace('_', ' ', $quoteSummaryVersion->approval_status ?? 'not_required') }}
                        </div>
                        @if(! empty($quoteSummaryVersion->approval_required_reasons))
                            <div class="small text-warning">{{ implode(' / ', $quoteSummaryVersion->approval_required_reasons) }}</div>
                        @endif
                        @foreach($quotePresentation['groups'] as $group)
                            <div class="small">{{ $group['summary_label'] }}: {{ number_format((float) $group['total_ex_vat'], 2, ',', ' ') }} {{ $group['unit'] }} ex VAT</div>
                        @endforeach
                    @else
                        <div class="small text-muted">No quote prepared yet.</div>
                    @endif
                </div>
                <div class="d-flex gap-2">
                    @if($sale->currentQuoteVersion)
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#quoteDetails" aria-expanded="false" aria-controls="quoteDetails">
                            Details
                        </button>
                        <a href="{{ route('sales.quotes.public.view', $sale->currentQuoteVersion->secure_token) }}" class="btn btn-sm btn-outline-primary" target="_blank">Portal</a>
                        <a href="{{ route('sales.quotes.public.pdf', $sale->currentQuoteVersion->secure_token) }}" class="btn btn-sm btn-outline-secondary" target="_blank">PDF</a>
                        @if($sale->currentQuoteVersion->status === 'draft')
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#quoteLineModal">Edit Quote</button>
                            <form method="POST" action="{{ route('tech.sales.quote.approval.request', $sale) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Check Approval</button>
                            </form>
                            @can('sales.quote.approve')
                                @if($sale->currentQuoteVersion->approval_status === 'pending')
                                    <form method="POST" action="{{ route('tech.sales.quote.approval.approve', $sale) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('tech.sales.quote.approval.changes', $sale) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-warning">Request Changes</button>
                                    </form>
                                @endif
                            @endcan
                            @if($quoteLineCount > 0)
                                <form method="POST" action="{{ route('tech.sales.quote.send', $sale) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success">Send Quote</button>
                                </form>
                            @else
                                <span
                                    class="d-inline-block"
                                    title="Add at least one quote line before sending.">
                                    <button type="button" class="btn btn-sm btn-disabled" disabled>Send Quote</button>
                                </span>
                            @endif
                        @elseif($sale->currentQuoteVersion->status === 'sent')
                            @if($sale->status === 'negotiation')
                                <form method="POST" action="{{ route('tech.sales.quote.revise', $sale) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Revise Quote</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('tech.sales.quote.send', $sale) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-success" @disabled(! $sale->primaryContact?->email)>Send Email</button>
                            </form>
                        @endif
                    @else
                        <form method="POST" action="{{ route('tech.sales.quote.ensure', $sale) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">Prepare Quote</button>
                        </form>
                    @endif
                </div>
            </div>
            <div class="collapse" id="quoteDetails">
            <div class="card-body border-top">
                @if($sale->currentQuoteVersion)
                    @php
                        $version = $sale->currentQuoteVersion->loadMissing('lines');
                    @endphp
                    <h3 class="h6">Customer presentation</h3>
                    @if($version->approval_status && $version->approval_status !== 'not_required')
                        <div class="alert alert-warning py-2">
                            <div class="fw-semibold">Approval: {{ ucfirst(str_replace('_', ' ', $version->approval_status)) }}</div>
                            @if(! empty($version->approval_required_reasons))
                                <ul class="mb-0 small">
                                    @foreach($version->approval_required_reasons as $reason)
                                        <li>{{ $reason }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if($version->approval_decision_note)
                                <div class="small mt-1">{{ $version->approval_decision_note }}</div>
                            @endif
                        </div>
                    @endif
                    @foreach($quotePresentation['before_copy'] as $section)
                        <div class="mb-3">
                            <h4 class="h6">{{ $section['label'] }}</h4>
                            <div style="white-space: pre-wrap;">{{ $section['text'] }}</div>
                        </div>
                    @endforeach
                    @include('sales::Partials.quote-groups', ['quotePresentation' => $quotePresentation])
                    @foreach($quotePresentation['after_copy'] as $section)
                        <div class="mb-3">
                            <h4 class="h6">{{ $section['label'] }}</h4>
                            <div style="white-space: pre-wrap;">{{ $section['text'] }}</div>
                        </div>
                    @endforeach

                    <h3 class="h6 mt-4">Internal line detail</h3>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>Section</th>
                                <th>Billing</th>
                                <th>Line</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Cost</th>
                                <th class="text-end">Price</th>
                                <th class="text-end">Discount</th>
                                <th class="text-end">Total ex VAT</th>
                                <th class="text-end">Margin</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($version->lines as $line)
                                <tr>
                                    <td>{{ str_replace('_', ' ', $line->section) }}</td>
                                    <td>{{ $quoteCadences[$line->billing_cadence]['unit'] ?? $line->billing_cadence }}</td>
                                    <td>
                                        <span class="fw-semibold">{{ $line->name }}</span>
                                        <div class="text-muted small">{{ $line->description }}</div>
                                        <div class="small">
                                            @if($line->is_required)
                                                <span class="badge text-bg-light border">Required</span>
                                            @elseif($line->is_recommended)
                                                <span class="badge text-bg-primary">Recommended</span>
                                            @else
                                                <span class="badge text-bg-light border">Optional</span>
                                            @endif
                                            @if($line->optionGroup)
                                                <span class="badge text-bg-light border">{{ $line->optionGroup->name }}</span>
                                            @endif
                                            @if($line->customer_quantity_editable)
                                                <span class="badge text-bg-light border">Customer quantity</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-end">{{ $line->quantity }}</td>
                                    <td class="text-end">{{ number_format((float) $line->unit_cost_ex_vat, 2, ',', ' ') }}</td>
                                    <td class="text-end">{{ number_format((float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                                    <td class="text-end">{{ $line->discount_value }} {{ $line->discount_type === 'percent' ? '%' : 'NOK' }}</td>
                                    <td class="text-end">{{ number_format((float) $line->line_total_ex_vat, 2, ',', ' ') }}</td>
                                    <td class="text-end">{{ number_format((float) $line->margin_percent, 1, ',', ' ') }}%</td>
                                    <td class="text-end">
                                        @if($version->isEditable())
                                            <form method="POST" action="{{ route('tech.sales.quote.lines.destroy', [$sale, $line]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger">Remove</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($version->status === 'accepted' && $version->acceptanceSnapshot)
                        <h3 class="h6 mt-4">Accepted snapshot</h3>
                        <dl class="row small mb-3">
                            <dt class="col-md-3">Accepted total ex VAT</dt>
                            <dd class="col-md-3">{{ number_format((float) data_get($version->acceptanceSnapshot->totals, 'total_ex_vat'), 2, ',', ' ') }}</dd>
                            <dt class="col-md-3">Selected lines</dt>
                            <dd class="col-md-3">{{ count($version->acceptanceSnapshot->selected_line_ids ?: []) }}</dd>
                        </dl>
                    @endif
                    @if($version->conversionPlans->isNotEmpty())
                        <h3 class="h6 mt-4">Conversion plan</h3>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                <tr>
                                    <th>Target</th>
                                    <th>Line</th>
                                    <th>Status</th>
                                    <th>Update</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($version->conversionPlans as $plan)
                                    <tr>
                                        <td>{{ $plan->target_domain }} / {{ str_replace('_', ' ', $plan->target_type) }}</td>
                                        <td>{{ data_get($plan->accepted_line_snapshot, 'name') }}</td>
                                        <td><span class="badge text-bg-light border">{{ str_replace('_', ' ', $plan->status) }}</span></td>
                                        <td>
                                            <form method="POST" action="{{ route('tech.sales.quote.conversion-plans.update', [$sale, $plan]) }}" class="row g-2 align-items-end">
                                                @csrf
                                                <div class="col-md-3">
                                                    <label class="form-label small">Status</label>
                                                    <select name="status" class="form-select form-select-sm">
                                                        @foreach(['pending', 'in_progress', 'completed', 'deferred', 'blocked', 'not_applicable'] as $status)
                                                            <option value="{{ $status }}" @selected($plan->status === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label small">Reference</label>
                                                    <input name="target_reference" class="form-control form-control-sm" value="{{ $plan->target_reference }}">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small">Note</label>
                                                    <input name="operator_note" class="form-control form-control-sm" value="{{ $plan->operator_note }}">
                                                </div>
                                                <div class="col-md-2 d-grid">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @else
                    <p class="text-muted mb-0">Prepare a quote to start adding lines.</p>
                @endif
            </div>
            </div>
        </div>

        @if($sale->currentQuoteVersion?->isEditable())
            <!-- ------------------------------------------------- -->
            <!-- Quote editing modal -->
            <!-- ------------------------------------------------- -->
            <div class="modal fade" id="quoteLineModal" tabindex="-1" aria-labelledby="quoteLineModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="quoteLineModalLabel">Edit Quote</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            @php
                                $editableVersion = $sale->currentQuoteVersion->loadMissing(['lines.optionGroup', 'optionGroups', 'acknowledgements']);
                                $quoteLevelAcknowledgement = $editableVersion->acknowledgements
                                    ->whereNull('quote_line_id')
                                    ->firstWhere('source_type', 'manual_quote_details');
                            @endphp
                            @if($quoteTemplates->isNotEmpty())
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h3 class="h6 mb-0">Quote template</h3>
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" action="{{ route('tech.sales.quote.templates.apply', $sale) }}" class="row g-3 align-items-end">
                                            @csrf
                                            <div class="col-md-8">
                                                <label for="quote_template_id" class="form-label">Template</label>
                                                <select id="quote_template_id" name="template_id" class="form-select" required>
                                                    @foreach($quoteTemplates as $template)
                                                        <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->lines_count }} lines)</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <div class="form-check">
                                                    <input type="hidden" name="replace_existing" value="0">
                                                    <input type="checkbox" id="replace_existing_template_lines" name="replace_existing" value="1" class="form-check-input">
                                                    <label for="replace_existing_template_lines" class="form-check-label">Replace existing</label>
                                                </div>
                                            </div>
                                            <div class="col-md-2 d-grid">
                                                <button type="submit" class="btn btn-outline-primary">Apply template</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            @endif
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h3 class="h6 mb-0">Customer-facing quote text</h3>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="{{ route('tech.sales.quote.details.update', $sale) }}" class="row g-3">
                                        @csrf
                                        @method('PATCH')
                                        <div class="col-md-8">
                                            <label for="quote_title" class="form-label">Quote title</label>
                                            <input id="quote_title" type="text" name="title" class="form-control" maxlength="255" value="{{ old('title', $editableVersion->title) }}" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="quote_expires_at" class="form-label">Expires</label>
                                            <input id="quote_expires_at" type="date" name="expires_at" class="form-control" value="{{ old('expires_at', $editableVersion->expires_at?->toDateString()) }}">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="quote_intro_text" class="form-label">Introduction</label>
                                            <textarea id="quote_intro_text" name="intro_text" class="form-control" rows="4" maxlength="20000">{{ old('intro_text', $editableVersion->intro_text) }}</textarea>
                                            <div class="form-text">Shown before the quote lines.</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="quote_scope_text" class="form-label">Solution and scope</label>
                                            <textarea id="quote_scope_text" name="scope_text" class="form-control" rows="4" maxlength="20000">{{ old('scope_text', $editableVersion->scope_text) }}</textarea>
                                            <div class="form-text">Shown before the quote lines.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="quote_assumptions_text" class="form-label">Assumptions and alternatives</label>
                                            <textarea id="quote_assumptions_text" name="assumptions_text" class="form-control" rows="4" maxlength="20000">{{ old('assumptions_text', $editableVersion->assumptions_text) }}</textarea>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="quote_exclusions_text" class="form-label">Exclusions</label>
                                            <textarea id="quote_exclusions_text" name="exclusions_text" class="form-control" rows="4" maxlength="20000">{{ old('exclusions_text', $editableVersion->exclusions_text) }}</textarea>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="quote_next_steps_text" class="form-label">Next steps</label>
                                            <textarea id="quote_next_steps_text" name="next_steps_text" class="form-control" rows="4" maxlength="20000">{{ old('next_steps_text', $editableVersion->next_steps_text) }}</textarea>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="quote_acknowledgement_title" class="form-label">Acknowledgement title</label>
                                            <input id="quote_acknowledgement_title" type="text" name="acknowledgement_title" class="form-control" maxlength="255" value="{{ old('acknowledgement_title', $quoteLevelAcknowledgement?->title) }}">
                                        </div>
                                        <div class="col-md-8">
                                            <label for="quote_acknowledgement_body" class="form-label">Required acknowledgement</label>
                                            <textarea id="quote_acknowledgement_body" name="acknowledgement_body" class="form-control" rows="3" maxlength="20000">{{ old('acknowledgement_body', $quoteLevelAcknowledgement?->body) }}</textarea>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check mt-2">
                                                <input type="hidden" name="acknowledgement_required" value="0">
                                                <input type="checkbox" name="acknowledgement_required" value="1" id="quote_acknowledgement_required" class="form-check-input" @checked(old('acknowledgement_required', $quoteLevelAcknowledgement?->is_required ?? true))>
                                                <label for="quote_acknowledgement_required" class="form-check-label">Customer must confirm</label>
                                            </div>
                                        </div>
                                        <div class="col-12 d-flex align-items-center justify-content-between gap-3">
                                            <div class="form-text">Assumptions, exclusions, and next steps appear after the price groups.</div>
                                            <button type="submit" class="btn btn-primary">Save quote text</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <div class="card mb-3">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h3 class="h6 mb-0">Quote lines</h3>
                                    <span class="badge text-bg-light border">{{ $editableVersion->lines->count() }}</span>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                            <tr>
                                                <th>Line</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Price</th>
                                                <th class="text-end">Total ex VAT</th>
                                                <th class="text-end">Actions</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @forelse($editableVersion->lines as $line)
                                                @php
                                                    $lineAcknowledgement = $editableVersion->acknowledgements
                                                        ->where('quote_line_id', $line->id)
                                                        ->firstWhere('source_type', 'manual_line');
                                                @endphp
                                                <tr>
                                                    <td>
                                                        <span class="fw-semibold">{{ $line->name }}</span>
                                                        <div class="text-muted small">{{ $line->description }}</div>
                                                        <div class="small">
                                                            {{ $quoteCadences[$line->billing_cadence]['label'] ?? $line->billing_cadence }}
                                                            @if($line->is_required)
                                                                / Required
                                                            @elseif($line->is_recommended)
                                                                / Recommended
                                                            @else
                                                                / Optional
                                                            @endif
                                                            @if($line->optionGroup)
                                                                / {{ $line->optionGroup->name }}
                                                            @endif
                                                        </div>
                                                    </td>
                                                    <td class="text-end">{{ $line->quantity }}</td>
                                                    <td class="text-end">{{ number_format((float) $line->unit_price_ex_vat, 2, ',', ' ') }}</td>
                                                    <td class="text-end">{{ number_format((float) $line->line_total_ex_vat, 2, ',', ' ') }}</td>
                                                    <td class="text-end">
                                                        <div class="d-inline-flex gap-1">
                                                            <button
                                                                type="button"
                                                                class="btn btn-sm btn-outline-primary quote-line-edit"
                                                                data-action="{{ route('tech.sales.quote.lines.update', [$sale, $line]) }}"
                                                                data-source-type="{{ $line->source_type }}"
                                                                data-source-id="{{ $line->source_id }}"
                                                                data-section="{{ $line->section }}"
                                                                data-downstream-type="{{ $line->downstream_type }}"
                                                                data-billing-cadence="{{ $line->billing_cadence }}"
                                                                data-name="{{ $line->name }}"
                                                                data-description="{{ $line->description }}"
                                                                data-quantity="{{ $line->quantity }}"
                                                                data-unit-price="{{ $line->unit_price_ex_vat }}"
                                                                data-unit-cost="{{ $line->unit_cost_ex_vat }}"
                                                                data-discount-value="{{ $line->discount_value }}"
                                                                data-discount-type="{{ $line->discount_type }}"
                                                                data-vat-rate="{{ $line->vat_rate }}"
                                                                data-is-optional="{{ $line->is_optional ? '1' : '0' }}"
                                                                data-is-required="{{ $line->is_required ? '1' : '0' }}"
                                                                data-is-recommended="{{ $line->is_recommended ? '1' : '0' }}"
                                                                data-customer-selected-by-default="{{ $line->customer_selected_by_default ? '1' : '0' }}"
                                                                data-customer-quantity-editable="{{ $line->customer_quantity_editable ? '1' : '0' }}"
                                                                data-min-customer-quantity="{{ $line->min_customer_quantity }}"
                                                                data-max-customer-quantity="{{ $line->max_customer_quantity }}"
                                                                data-customer-label="{{ $line->customer_label }}"
                                                                data-option-group-name="{{ $line->optionGroup?->name }}"
                                                                data-option-group-type="{{ $line->optionGroup?->type }}"
                                                                data-option-group-description="{{ $line->optionGroup?->description }}"
                                                                data-option-group-min-select="{{ $line->optionGroup?->min_select }}"
                                                                data-option-group-max-select="{{ $line->optionGroup?->max_select }}"
                                                                data-line-acknowledgement-title="{{ $lineAcknowledgement?->title }}"
                                                                data-line-acknowledgement-body="{{ $lineAcknowledgement?->body }}"
                                                                data-line-acknowledgement-required="{{ $lineAcknowledgement?->is_required ? '1' : '0' }}"
                                                            >Edit</button>
                                                            <form method="POST" action="{{ route('tech.sales.quote.lines.destroy', [$sale, $line]) }}">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button class="btn btn-sm btn-outline-danger">Remove</button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="5" class="text-muted p-3">No quote lines yet.</td>
                                                </tr>
                                            @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header">
                                    <h3 class="h6 mb-0" id="quoteLineFormTitle">Add line</h3>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="{{ route('tech.sales.quote.lines.store', $sale) }}" id="quoteLineForm" class="row g-3 align-items-end" data-store-action="{{ route('tech.sales.quote.lines.store', $sale) }}" data-store-method="POST" data-update-method="PATCH">
                                        @csrf
                                        <input type="hidden" name="_method" id="quoteLineMethod" value="POST">
                                        <div class="col-md-3">
                                            <label class="form-label">Source</label>
                                            <select name="source_type" class="form-select" id="quoteSourceType">
                                                <option value="custom">Custom</option>
                                                <option value="service">Service</option>
                                                <option value="package">Package</option>
                                                <option value="time_rate">Time rate</option>
                                                <option value="storage_item">Storage item</option>
                                            </select>
                                        </div>
                                        <div class="col-md-5" id="quoteCatalogPickerWrap">
                                            <label class="form-label" for="quoteCatalogSearch">Catalog item</label>
                                            <input type="text" id="quoteCatalogSearch" class="form-control" list="quoteCatalogOptions" autocomplete="off" placeholder="Start typing to search" title="Search and select an existing catalog item.">
                                            <input type="hidden" name="source_id" id="quoteSourceId">
                                            <datalist id="quoteCatalogOptions"></datalist>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Section</label>
                                            <select name="section" class="form-select">
                                                <option value="monthly_services">Monthly services</option>
                                                <option value="one_time_costs">One-time costs</option>
                                                <option value="equipment">Equipment</option>
                                                <option value="implementation">Implementation</option>
                                                <option value="optional">Optional</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Downstream</label>
                                            <select name="downstream_type" class="form-select">
                                                <option value="recurring_contract">Contract</option>
                                                <option value="one_time_order">Order</option>
                                                <option value="equipment">Equipment</option>
                                                <option value="implementation">Implementation</option>
                                                <option value="non_billable">Non-billable</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Billing cadence</label>
                                            <select name="billing_cadence" class="form-select" id="quoteLineBillingCadence" required>
                                                @foreach($quoteCadences as $cadenceKey => $cadence)
                                                    <option value="{{ $cadenceKey }}" @selected($cadenceKey === 'one_time')>{{ $cadence['label'] }} ({{ $cadence['unit'] }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label">Name</label>
                                            <input type="text" name="name" id="quoteLineName" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Qty</label>
                                            <input type="number" name="quantity" id="quoteLineQuantity" step="0.01" value="1" class="form-control" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Price</label>
                                            <input type="number" name="unit_price_ex_vat" id="quoteLinePrice" step="0.01" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Cost</label>
                                            <input type="number" name="unit_cost_ex_vat" id="quoteLineCost" step="0.01" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Discount</label>
                                            <input type="number" name="discount_value" id="quoteLineDiscountValue" step="0.01" value="0" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Discount type</label>
                                            <select name="discount_type" id="quoteLineDiscountType" class="form-select">
                                                <option value="amount">Amount</option>
                                                <option value="percent">Percent</option>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">VAT %</label>
                                            <input type="number" name="vat_rate" id="quoteLineVat" step="0.01" value="25" class="form-control">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Option group</label>
                                            <input type="text" name="option_group_name" id="quoteLineOptionGroupName" class="form-control" placeholder="Add-ons, Good / Better / Best, alternatives">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Group type</label>
                                            <select name="option_group_type" id="quoteLineOptionGroupType" class="form-select">
                                                @foreach(\App\Modules\Sales\Models\SalesQuoteOptionGroup::TYPES as $groupType => $label)
                                                    <option value="{{ $groupType }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Min choices</label>
                                            <input type="number" name="option_group_min_select" id="quoteLineOptionGroupMin" min="0" step="1" value="0" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Max choices</label>
                                            <input type="number" name="option_group_max_select" id="quoteLineOptionGroupMax" min="1" step="1" class="form-control">
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label">Group description</label>
                                            <input type="text" name="option_group_description" id="quoteLineOptionGroupDescription" class="form-control">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Customer label</label>
                                            <input type="text" name="customer_label" id="quoteLineCustomerLabel" class="form-control">
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label">Explanation</label>
                                            <input type="text" name="description" id="quoteLineDescription" class="form-control">
                                        </div>
                                        <div class="col-md-4">
                                            <div class="border rounded p-2">
                                                <div class="form-check">
                                                    <input type="hidden" name="is_required" value="0">
                                                    <input type="checkbox" name="is_required" value="1" id="quoteLineRequired" class="form-check-input" checked>
                                                    <label for="quoteLineRequired" class="form-check-label">Required/included line</label>
                                                </div>
                                                <div class="form-check">
                                                    <input type="hidden" name="is_recommended" value="0">
                                                    <input type="checkbox" name="is_recommended" value="1" id="quoteLineRecommended" class="form-check-input">
                                                    <label for="quoteLineRecommended" class="form-check-label">Recommended option</label>
                                                </div>
                                                <div class="form-check">
                                                    <input type="hidden" name="customer_selected_by_default" value="0">
                                                    <input type="checkbox" name="customer_selected_by_default" value="1" id="quoteLineDefaultSelected" class="form-check-input" checked>
                                                    <label for="quoteLineDefaultSelected" class="form-check-label">Selected by default</label>
                                                </div>
                                                <div class="form-check">
                                                    <input type="hidden" name="customer_quantity_editable" value="0">
                                                    <input type="checkbox" name="customer_quantity_editable" value="1" id="quoteLineCustomerQuantityEditable" class="form-check-input">
                                                    <label for="quoteLineCustomerQuantityEditable" class="form-check-label">Customer can change quantity</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Min customer qty</label>
                                            <input type="number" name="min_customer_quantity" id="quoteLineMinCustomerQuantity" step="0.01" min="0.01" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">Max customer qty</label>
                                            <input type="number" name="max_customer_quantity" id="quoteLineMaxCustomerQuantity" step="0.01" min="0.01" class="form-control">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Line acknowledgement title</label>
                                            <input type="text" name="line_acknowledgement_title" id="quoteLineAcknowledgementTitle" class="form-control">
                                        </div>
                                        <div class="col-md-8">
                                            <label class="form-label">Line acknowledgement</label>
                                            <input type="text" name="line_acknowledgement_body" id="quoteLineAcknowledgementBody" class="form-control">
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-check mt-4">
                                                <input type="hidden" name="line_acknowledgement_required" value="0">
                                                <input type="checkbox" name="line_acknowledgement_required" value="1" id="quoteLineAcknowledgementRequired" class="form-check-input" checked>
                                                <label for="quoteLineAcknowledgementRequired" class="form-check-label">Required</label>
                                            </div>
                                        </div>
                                        <div class="col-md-2 d-grid gap-2">
                                            <button type="submit" class="btn btn-primary" id="quoteLineSubmit">Add line</button>
                                            <button type="button" class="btn btn-outline-secondary d-none" id="quoteLineEditCancel">Cancel edit</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Sales activity combines journal, internal notes, customer emails, and quote replies. --}}
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-3">
                <div>Activity</div>
                <div class="d-flex flex-wrap gap-2">
                    @if($sale->is_unread)
                        <form method="POST" action="{{ route('tech.sales.read', $sale) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-primary">Mark all read</button>
                        </form>
                    @endif
                    <button type="button" class="btn btn-sm btn-outline-primary sales-compose-shortcut" data-activity-type="email_out">Reply</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary sales-compose-shortcut" data-activity-type="internal_note">Internal note</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary sales-compose-shortcut" data-activity-type="journal">Log call</button>
                </div>
            </div>
            <div class="card-body">
                @if($sale->activities->isNotEmpty())
                    <div class="accordion accordion-flush" id="salesActivityAccordion">
                        @foreach($sale->activities as $activity)
                            @php
                                $activityCollapseId = 'salesActivityCollapse' . $activity->id;
                                $activityHeadingId = 'salesActivityHeading' . $activity->id;
                                $isUnreadActivity = $activity->is_unread && $activity->direction === 'inbound';
                                $activityLabel = match ($activity->type) {
                                    'email_out' => 'Prospect reply',
                                    'email_in' => 'Prospect email',
                                    'internal_note' => 'Internal note',
                                    'quote_email_queued' => 'Quote email',
                                    'quote_sent' => 'Quote sent',
                                    'quote_accepted' => 'Quote accepted',
                                    'quote_declined' => 'Quote declined',
                                    'quote_expired' => 'Quote expired',
                                    'quote_viewed' => 'Quote viewed',
                                    'quote_template_applied' => 'Quote template',
                                    'quote_approval_requested' => 'Approval requested',
                                    'quote_approval_approved' => 'Quote approved',
                                    'quote_approval_rejected' => 'Quote rejected',
                                    'quote_approval_changes_requested' => 'Quote changes requested',
                                    'quote_conversion_plan_updated' => 'Conversion plan',
                                    default => ucfirst(str_replace('_', ' ', $activity->type)),
                                };
                                $participantLine = $activity->direction === 'inbound'
                                    ? trim(($activity->metadata['from_name'] ?? $activity->metadata['name'] ?? 'Prospect') . ' ' . (($activity->metadata['from_email'] ?? $activity->metadata['email'] ?? null) ? '<' . ($activity->metadata['from_email'] ?? $activity->metadata['email']) . '>' : ''))
                                    : ($activity->actor?->name ?? 'Sales');
                                $activityExcerpt = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', trim((string) $activity->body)), 120);
                            @endphp
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="{{ $activityHeadingId }}">
                                    <button class="accordion-button py-2 px-0 {{ $isUnreadActivity ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $activityCollapseId }}" aria-expanded="{{ $isUnreadActivity ? 'true' : 'false' }}" aria-controls="{{ $activityCollapseId }}">
                                        <span class="d-flex align-items-center gap-2 w-100 pe-3 text-start min-w-0">
                                            <span class="fw-semibold flex-shrink-0">{{ $activityLabel }}</span>
                                            @if($isUnreadActivity)
                                                <span class="text-primary small fw-semibold flex-shrink-0">Unread</span>
                                            @endif
                                            <span class="small text-muted text-truncate flex-shrink-0" style="max-width: 14rem;">{{ $participantLine ?: 'Sales' }}</span>
                                            <span class="small text-body text-truncate min-w-0 flex-grow-1">{{ $activityExcerpt !== '' ? $activityExcerpt : 'No activity text.' }}</span>
                                            <span class="text-muted small flex-shrink-0">{{ $activity->created_at?->diffForHumans() }}</span>
                                        </span>
                                    </button>
                                </h2>
                                <div id="{{ $activityCollapseId }}" class="accordion-collapse collapse {{ $isUnreadActivity ? 'show' : '' }}" aria-labelledby="{{ $activityHeadingId }}">
                                    <div class="accordion-body px-0 pt-2 pb-3">
                                        @if($isUnreadActivity)
                                            <div class="d-flex justify-content-end mb-2">
                                                <form method="POST" action="{{ route('tech.sales.activities.read', [$sale, $activity]) }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Mark as read</button>
                                                </form>
                                            </div>
                                        @endif
                                        @if(($activity->metadata['to_email'] ?? null) || ($activity->metadata['notify_user_id'] ?? null))
                                            <div class="small text-muted mb-2">
                                                @if($activity->metadata['to_email'] ?? null)
                                                    To: {{ $activity->metadata['to_name'] ?? '' }} &lt;{{ $activity->metadata['to_email'] }}&gt;
                                                @endif
                                                @if(! empty($activity->metadata['cc'] ?? []))
                                                    / CC: {{ collect($activity->metadata['cc'])->pluck('email')->implode(', ') }}
                                                @endif
                                                @if($activity->metadata['notify_user_id'] ?? null)
                                                    / Notify user #{{ $activity->metadata['notify_user_id'] }}
                                                @endif
                                            </div>
                                        @endif
                                        <div style="white-space: pre-wrap;">{{ $activity->body }}</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-muted mb-0">No activity yet.</p>
                @endif
            </div>
        </div>

        {{-- Add sales message composer. Mirrors the ticket composer placement and collapse behavior. --}}
        <div class="accordion" id="salesComposerAccordion">
            <div class="accordion-item border rounded overflow-hidden">
                <h2 class="accordion-header" id="salesComposerHeading">
                    <button class="accordion-button py-2 collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#salesComposerCollapse" aria-expanded="false" aria-controls="salesComposerCollapse">
                        Add message
                    </button>
                </h2>
                <div id="salesComposerCollapse" class="accordion-collapse collapse" aria-labelledby="salesComposerHeading" data-bs-parent="#salesComposerAccordion">
                    <div class="accordion-body">
                        <form method="POST" action="{{ route('tech.sales.activities.store', $sale) }}" id="salesActivityForm">
                            @csrf

                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label for="salesActivityType" class="form-label">Message type</label>
                                    <select name="type" class="form-select" id="salesActivityType">
                                        <option value="email_out">Reply to prospect</option>
                                        <option value="internal_note">Internal note</option>
                                        <option value="journal">Sales journal</option>
                                        <option value="email_in">Log inbound reply</option>
                                    </select>
                                </div>
                                <div class="col-md-6 d-none" id="salesInternalNotifyFields">
                                    <label class="form-label">Notify colleague</label>
                                    <select name="notify_user_id" class="form-select">
                                        <option value="">Do not notify</option>
                                        @foreach($owners as $owner)
                                            <option value="{{ $owner->id }}">{{ $owner->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div id="salesEmailRecipientFields" class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Contact</label>
                                    <select name="recipient_contact_id" class="form-select">
                                        <option value="">Manual email address</option>
                                        @foreach($sale->client?->contacts ?? [] as $contact)
                                            @if($contact->email)
                                                <option value="{{ $contact->id }}" @selected($sale->primary_contact_id === $contact->id)>{{ $contact->name }} &lt;{{ $contact->email }}&gt;</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Manual email</label>
                                    <input type="email" name="to_email" class="form-control" placeholder="prospect@example.com">
                                </div>
                            </div>

                            <div id="salesEmailCcFields" class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">CC</label>
                                    <input type="text" name="cc" class="form-control" placeholder="thirdparty@example.com">
                                </div>
                                <div class="col-md-6">
                                    <label for="salesActivitySubject" class="form-label">Subject</label>
                                    <input id="salesActivitySubject" type="text" name="subject" class="form-control" placeholder="Subject">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="salesActivityBody">Message</label>
                                <textarea id="salesActivityBody" name="body" class="form-control" rows="5" placeholder="Write the prospect reply, internal note, or sales journal..." required></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary mt-3" id="salesActivitySubmit">Add message</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    @php
        $quoteCatalogs = [
            'service' => $services->map(fn($service) => [
                'id' => $service->id,
                'label' => trim(($service->sku ? $service->sku.' - ' : '').$service->name),
                'name' => $service->name,
                'description' => $service->short_description,
                'price' => $service->price_ex_vat,
                'cost' => $service->costRelations->sum(fn($relation) => (float) ($relation->cost?->cost ?? 0)),
                'vat' => 25,
            ])->values(),
            'package' => $packages->map(fn($package) => [
                'id' => $package->id,
                'label' => $package->name,
                'name' => $package->name,
                'description' => $package->description,
                'price' => $package->sales_price_client,
                'cost' => $package->services->sum(
                    fn($service) => $service->costRelations->sum(fn($relation) => (float) ($relation->cost?->cost ?? 0))
                ),
                'vat' => 25,
            ])->values(),
            'time_rate' => $rates->map(fn($rate) => [
                'id' => $rate->id,
                'label' => trim(($rate->code ? $rate->code.' - ' : '').$rate->name),
                'name' => $rate->name,
                'description' => $rate->description,
                'price' => $rate->amount_ex_vat,
                'cost' => null,
                'vat' => 25,
            ])->values(),
            'storage_item' => $storageItems->map(fn($item) => [
                'id' => $item->id,
                'label' => trim(($item->sku ? $item->sku.' - ' : '').$item->name),
                'name' => $item->name,
                'description' => $item->short_description,
                'price' => $item->sale_price,
                'cost' => $item->purchase_price,
                'vat' => $item->vat_rate,
                'stock' => $item->qty_available,
            ])->values(),
        ];
    @endphp
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const clientContactData = @json($clientContactData);
            const clientId = @json($sale->client_id);
            const contactForm = document.getElementById('quickContactForm');
            const contactSelect = document.getElementById('primary_contact_id');
            const contactErrorBox = document.getElementById('quickContactErrors');
            const contactSubmitButton = document.getElementById('quickContactSubmit');
            const contactModalElement = document.getElementById('quickContactModal');
            const contactModal = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(contactModalElement) : null;
            const sourceType = document.getElementById('quoteSourceType');
            const pickerWrap = document.getElementById('quoteCatalogPickerWrap');
            const search = document.getElementById('quoteCatalogSearch');
            const options = document.getElementById('quoteCatalogOptions');
            const sourceId = document.getElementById('quoteSourceId');
            const nameInput = document.getElementById('quoteLineName');
            const descriptionInput = document.getElementById('quoteLineDescription');
            const priceInput = document.getElementById('quoteLinePrice');
            const costInput = document.getElementById('quoteLineCost');
            const vatInput = document.getElementById('quoteLineVat');
            const quoteLineForm = document.getElementById('quoteLineForm');
            const quoteLineMethod = document.getElementById('quoteLineMethod');
            const quoteLineTitle = document.getElementById('quoteLineFormTitle');
            const quoteLineSubmit = document.getElementById('quoteLineSubmit');
            const editCancel = document.getElementById('quoteLineEditCancel');
            const quantityInput = document.getElementById('quoteLineQuantity');
            const discountValueInput = document.getElementById('quoteLineDiscountValue');
            const discountTypeInput = document.getElementById('quoteLineDiscountType');
            const requiredInput = document.getElementById('quoteLineRequired');
            const recommendedInput = document.getElementById('quoteLineRecommended');
            const defaultSelectedInput = document.getElementById('quoteLineDefaultSelected');
            const customerQuantityEditableInput = document.getElementById('quoteLineCustomerQuantityEditable');
            const minCustomerQuantityInput = document.getElementById('quoteLineMinCustomerQuantity');
            const maxCustomerQuantityInput = document.getElementById('quoteLineMaxCustomerQuantity');
            const customerLabelInput = document.getElementById('quoteLineCustomerLabel');
            const optionGroupNameInput = document.getElementById('quoteLineOptionGroupName');
            const optionGroupTypeInput = document.getElementById('quoteLineOptionGroupType');
            const optionGroupDescriptionInput = document.getElementById('quoteLineOptionGroupDescription');
            const optionGroupMinInput = document.getElementById('quoteLineOptionGroupMin');
            const optionGroupMaxInput = document.getElementById('quoteLineOptionGroupMax');
            const lineAcknowledgementTitleInput = document.getElementById('quoteLineAcknowledgementTitle');
            const lineAcknowledgementBodyInput = document.getElementById('quoteLineAcknowledgementBody');
            const lineAcknowledgementRequiredInput = document.getElementById('quoteLineAcknowledgementRequired');
            const activityType = document.getElementById('salesActivityType');
            const emailRecipientFields = document.getElementById('salesEmailRecipientFields');
            const emailCcFields = document.getElementById('salesEmailCcFields');
            const internalNotifyFields = document.getElementById('salesInternalNotifyFields');
            const activitySubmit = document.getElementById('salesActivitySubmit');
            const activityBody = document.getElementById('salesActivityBody');
            const composerCollapseElement = document.getElementById('salesComposerCollapse');
            const composerCollapse = composerCollapseElement && window.bootstrap ? window.bootstrap.Collapse.getOrCreateInstance(composerCollapseElement, { toggle: false }) : null;

            const activitySubmitLabels = {
                email_out: 'Send reply',
                internal_note: 'Add internal note',
                journal: 'Log activity',
                email_in: 'Log inbound reply',
            };

            const activityPlaceholders = {
                email_out: 'Write the email reply to the prospect...',
                internal_note: 'Write an internal sales note...',
                journal: 'Log call notes, meeting outcome, objection, or next step...',
                email_in: 'Paste or summarize the prospect reply...',
            };

            const syncActivityFields = () => {
                const type = activityType?.value;
                const isEmail = type === 'email_out';
                const isInternalNote = type === 'internal_note';

                emailRecipientFields?.classList.toggle('d-none', !isEmail);
                emailCcFields?.classList.toggle('d-none', !isEmail);
                internalNotifyFields?.classList.toggle('d-none', !isInternalNote);

                if (activitySubmit && type) {
                    activitySubmit.textContent = activitySubmitLabels[type] || 'Add activity';
                }

                if (activityBody && type) {
                    activityBody.placeholder = activityPlaceholders[type] || 'Write sales activity...';
                }
            };

            activityType?.addEventListener('change', syncActivityFields);
            syncActivityFields();

            document.querySelectorAll('.sales-compose-shortcut').forEach((button) => {
                button.addEventListener('click', () => {
                    if (activityType) {
                        activityType.value = button.dataset.activityType || 'journal';
                        syncActivityFields();
                    }

                    composerCollapse?.show();
                    activityBody?.focus();
                });
            });

            const showContactErrors = (messages) => {
                contactErrorBox.classList.remove('d-none');
                contactErrorBox.innerHTML = '';
                messages.forEach((message) => {
                    const line = document.createElement('div');
                    line.textContent = message;
                    contactErrorBox.appendChild(line);
                });
            };

            const addContactOption = (contact) => {
                const option = new Option(contact.label, contact.id, true, true);
                contactSelect.add(option);
            };

            contactForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                contactErrorBox.classList.add('d-none');
                contactErrorBox.innerHTML = '';
                contactSubmitButton.disabled = true;
                contactSubmitButton.textContent = 'Creating...';

                try {
                    const response = await fetch(contactForm.dataset.storeUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': contactForm.querySelector('input[name="_token"]').value,
                        },
                        body: new FormData(contactForm),
                    });

                    const payload = await response.json();

                    if (!response.ok) {
                        const messages = payload.errors
                            ? Object.values(payload.errors).flat()
                            : [payload.message || 'Contact could not be created.'];
                        showContactErrors(messages);
                        return;
                    }

                    clientContactData[clientId] ||= { sites: [], contacts: [] };
                    clientContactData[clientId].contacts.push(payload.contact);
                    addContactOption(payload.contact);
                    contactForm.reset();

                    if (contactModal) {
                        contactModal.hide();
                    }
                } catch (error) {
                    showContactErrors(['Contact could not be created. Please try again.']);
                } finally {
                    contactSubmitButton.disabled = false;
                    contactSubmitButton.textContent = 'Create Contact';
                }
            });

            if (!sourceType || !search || !options) {
                return;
            }

            const catalogs = @json($quoteCatalogs);

            const labels = {
                service: 'Search services',
                package: 'Search packages',
                time_rate: 'Search time rates',
                storage_item: 'Search storage items',
            };

            const formatDecimal = (value) => {
                if (value === null || value === undefined || value === '') {
                    return '';
                }

                return Number(value).toFixed(2);
            };

            const currentCatalog = () => catalogs[sourceType.value] || [];

            const itemBySource = (type, id) => (catalogs[type] || []).find((item) => String(item.id) === String(id));

            const resetCatalogSelection = () => {
                search.value = '';
                sourceId.value = '';
                options.innerHTML = '';
            };

            const renderOptions = () => {
                options.innerHTML = '';
                currentCatalog().forEach((item) => {
                    const option = document.createElement('option');
                    option.value = item.label;
                    option.label = item.stock === undefined ? item.label : `${item.label} (${item.stock} available)`;
                    options.appendChild(option);
                });
            };

            const syncPickerVisibility = () => {
                const isCatalogSource = sourceType.value !== 'custom';
                pickerWrap.classList.toggle('d-none', !isCatalogSource);
                search.required = isCatalogSource;
                search.title = isCatalogSource
                    ? `${labels[sourceType.value] || 'Search catalog'} and select an existing item.`
                    : 'Custom lines do not need a catalog item.';
                resetCatalogSelection();
                renderOptions();
            };

            const applySelectedItem = () => {
                const selected = currentCatalog().find((item) => item.label === search.value);
                sourceId.value = selected ? selected.id : '';

                if (!selected) {
                    return;
                }

                nameInput.value = selected.name || '';
                descriptionInput.value = selected.description || '';
                priceInput.value = formatDecimal(selected.price);
                costInput.value = formatDecimal(selected.cost);
                vatInput.value = formatDecimal(selected.vat || 25);
            };

            const resetLineForm = () => {
                quoteLineForm.action = quoteLineForm.dataset.storeAction;
                quoteLineMethod.value = quoteLineForm.dataset.storeMethod || 'POST';
                quoteLineTitle.textContent = 'Add line';
                quoteLineSubmit.textContent = 'Add line';
                editCancel.classList.add('d-none');
                quoteLineForm.reset();
                sourceType.value = 'custom';
                requiredInput.checked = true;
                recommendedInput.checked = false;
                defaultSelectedInput.checked = true;
                customerQuantityEditableInput.checked = false;
                optionGroupTypeInput.value = 'optional';
                optionGroupMinInput.value = '0';
                optionGroupMaxInput.value = '';
                lineAcknowledgementRequiredInput.checked = true;
                syncPickerVisibility();
            };

            sourceType.addEventListener('change', syncPickerVisibility);
            search.addEventListener('input', applySelectedItem);
            syncPickerVisibility();

            document.querySelectorAll('.quote-line-edit').forEach((button) => {
                button.addEventListener('click', () => {
                    quoteLineForm.action = button.dataset.action;
                    quoteLineMethod.value = quoteLineForm.dataset.updateMethod || 'PATCH';
                    quoteLineTitle.textContent = 'Edit line';
                    quoteLineSubmit.textContent = 'Save line';
                    editCancel.classList.remove('d-none');

                    sourceType.value = button.dataset.sourceType || 'custom';
                    syncPickerVisibility();

                    const selected = itemBySource(button.dataset.sourceType, button.dataset.sourceId);
                    if (selected) {
                        search.value = selected.label;
                        sourceId.value = selected.id;
                    }

                    quoteLineForm.querySelector('[name="section"]').value = button.dataset.section || 'monthly_services';
                    quoteLineForm.querySelector('[name="downstream_type"]').value = button.dataset.downstreamType || 'one_time_order';
                    quoteLineForm.querySelector('[name="billing_cadence"]').value = button.dataset.billingCadence || 'one_time';
                    nameInput.value = button.dataset.name || '';
                    descriptionInput.value = button.dataset.description || '';
                    quantityInput.value = button.dataset.quantity || '1';
                    priceInput.value = button.dataset.unitPrice || '0';
                    costInput.value = button.dataset.unitCost || '0';
                    discountValueInput.value = button.dataset.discountValue || '0';
                    discountTypeInput.value = button.dataset.discountType || 'amount';
                    vatInput.value = button.dataset.vatRate || '25';
                    requiredInput.checked = button.dataset.isRequired === '1';
                    recommendedInput.checked = button.dataset.isRecommended === '1';
                    defaultSelectedInput.checked = button.dataset.customerSelectedByDefault !== '0';
                    customerQuantityEditableInput.checked = button.dataset.customerQuantityEditable === '1';
                    minCustomerQuantityInput.value = button.dataset.minCustomerQuantity || '';
                    maxCustomerQuantityInput.value = button.dataset.maxCustomerQuantity || '';
                    customerLabelInput.value = button.dataset.customerLabel || '';
                    optionGroupNameInput.value = button.dataset.optionGroupName || '';
                    optionGroupTypeInput.value = button.dataset.optionGroupType || 'optional';
                    optionGroupDescriptionInput.value = button.dataset.optionGroupDescription || '';
                    optionGroupMinInput.value = button.dataset.optionGroupMinSelect || '0';
                    optionGroupMaxInput.value = button.dataset.optionGroupMaxSelect || '';
                    lineAcknowledgementTitleInput.value = button.dataset.lineAcknowledgementTitle || '';
                    lineAcknowledgementBodyInput.value = button.dataset.lineAcknowledgementBody || '';
                    lineAcknowledgementRequiredInput.checked = button.dataset.lineAcknowledgementRequired !== '0';
                    quoteLineForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
            });

            quoteLineForm.addEventListener('submit', () => {
                quoteLineMethod.value = quoteLineForm.action === quoteLineForm.dataset.storeAction
                    ? (quoteLineForm.dataset.storeMethod || 'POST')
                    : (quoteLineForm.dataset.updateMethod || 'PATCH');
            });

            editCancel?.addEventListener('click', resetLineForm);

            @if(session('open_quote_modal') || $errors->any() || request()->boolean('open_quote'))
                const quoteModalElement = document.getElementById('quoteLineModal');
                if (quoteModalElement && window.bootstrap) {
                    window.bootstrap.Modal.getOrCreateInstance(quoteModalElement).show();
                }
            @endif
        });
    </script>
@endsection

@section('rightbar')
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Forecast</h5></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-7">Value</dt>
                <dd class="col-5 text-end">{{ number_format((float) $sale->estimated_value_ex_vat, 0, ',', ' ') }}</dd>
                <dt class="col-7">Probability</dt>
                <dd class="col-5 text-end">{{ $sale->probability_percent }}%</dd>
                <dt class="col-7">Weighted</dt>
                <dd class="col-5 text-end">{{ number_format((float) $sale->weighted_value_ex_vat, 0, ',', ' ') }}</dd>
            </dl>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Quote Sources</h5></div>
        <div class="card-body small">
            <div class="fw-semibold">Services</div>
            <div class="text-muted mb-2">{{ $services->count() }} available</div>
            <div class="fw-semibold">Packages</div>
            <div class="text-muted mb-2">{{ $packages->count() }} available</div>
            <div class="fw-semibold">Rates</div>
            <div class="text-muted mb-2">{{ $rates->count() }} available</div>
            <div class="fw-semibold">Storage items</div>
            <div class="text-muted">{{ $storageItems->count() }} shown</div>
        </div>
    </div>
@endsection
