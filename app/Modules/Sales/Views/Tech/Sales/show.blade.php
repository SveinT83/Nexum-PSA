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
        @unless($sale->currentQuoteVersion)
            <form method="POST" action="{{ route('tech.sales.quote.ensure', $sale) }}">
                @csrf
                <button type="submit" class="btn btn-primary"><i class="bi bi-file-earmark-text"></i> Prepare Quote</button>
            </form>
        @endunless
    </div>
@endsection

@section('content')
    <div class="container-fluid">
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
                                    @foreach($statuses as $key => $status)
                                        <option value="{{ $key }}" @selected($sale->status === $key)>{{ $status['label'] }}</option>
                                    @endforeach
                                </select>
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
                            {{ ucfirst($quoteSummaryVersion->status) }} / {{ $quoteSummaryVersion->lines->count() }} lines / {{ number_format((float) $quoteSummaryVersion->total_ex_vat, 2, ',', ' ') }} ex VAT
                        </div>
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
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>Section</th>
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
                                    <td>
                                        <span class="fw-semibold">{{ $line->name }}</span>
                                        <div class="text-muted small">{{ $line->description }}</div>
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
                    <div class="d-flex justify-content-end">
                        <dl class="row mb-0 text-end" style="min-width: 20rem;">
                            <dt class="col-7">Subtotal ex VAT</dt>
                            <dd class="col-5">{{ number_format((float) $version->total_ex_vat, 2, ',', ' ') }}</dd>
                            <dt class="col-7">VAT</dt>
                            <dd class="col-5">{{ number_format((float) $version->vat_total, 2, ',', ' ') }}</dd>
                            <dt class="col-7">Total inc VAT</dt>
                            <dd class="col-5 fw-semibold">{{ number_format((float) $version->total_inc_vat, 2, ',', ' ') }}</dd>
                        </dl>
                    </div>
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
                                $editableVersion = $sale->currentQuoteVersion->loadMissing('lines');
                            @endphp
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
                                                <tr>
                                                    <td>
                                                        <span class="fw-semibold">{{ $line->name }}</span>
                                                        <div class="text-muted small">{{ $line->description }}</div>
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
                                                                data-name="{{ $line->name }}"
                                                                data-description="{{ $line->description }}"
                                                                data-quantity="{{ $line->quantity }}"
                                                                data-unit-price="{{ $line->unit_price_ex_vat }}"
                                                                data-unit-cost="{{ $line->unit_cost_ex_vat }}"
                                                                data-discount-value="{{ $line->discount_value }}"
                                                                data-discount-type="{{ $line->discount_type }}"
                                                                data-vat-rate="{{ $line->vat_rate }}"
                                                                data-is-optional="{{ $line->is_optional ? '1' : '0' }}"
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
                                        <div class="col-md-8">
                                            <label class="form-label">Explanation</label>
                                            <input type="text" name="description" id="quoteLineDescription" class="form-control">
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
                    nameInput.value = button.dataset.name || '';
                    descriptionInput.value = button.dataset.description || '';
                    quantityInput.value = button.dataset.quantity || '1';
                    priceInput.value = button.dataset.unitPrice || '0';
                    costInput.value = button.dataset.unitCost || '0';
                    discountValueInput.value = button.dataset.discountValue || '0';
                    discountTypeInput.value = button.dataset.discountType || 'amount';
                    vatInput.value = button.dataset.vatRate || '25';
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
