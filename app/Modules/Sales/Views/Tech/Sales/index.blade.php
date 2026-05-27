@extends('layouts.default_tech')

@section('title', 'Sales')

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1>Sales</h1>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        {{-- Sales filters keep active pipeline work scannable. --}}
        <form method="GET" action="{{ route('tech.sales.index') }}" class="card mb-3">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="q" class="form-label">Search</label>
                        <input type="search" id="q" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="Opportunity, key, or client">
                    </div>
                    <div class="col-md-2">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">All statuses</option>
                            @foreach($statuses as $key => $status)
                                <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $status['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="owner" class="form-label">Owner</label>
                        <select id="owner" name="owner" class="form-select">
                            <option value="">All owners</option>
                            @foreach($owners as $owner)
                                <option value="{{ $owner->id }}" @selected(($filters['owner'] ?? '') == $owner->id)>{{ $owner->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="submit" class="btn btn-outline-primary">Apply</button>
                    </div>
                    <div class="col-md-2 d-grid">
                        <a href="{{ route('tech.sales.leads.index') }}" class="btn btn-outline-primary mb-0">
                            <i class="bi bi-person-plus"></i> Leads
                        </a>
                    </div>
                    <div class="col-md-2 d-grid">
                        <a href="{{ route('tech.sales.create') }}" class="btn btn-primary mb-0">
                            <i class="bi bi-plus-lg"></i> New Opportunity
                        </a>
                    </div>
                </div>
            </div>
        </form>

        {{-- Opportunity table is the primary working surface for sellers. --}}
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Opportunity</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Owner</th>
                        <th>Follow-up</th>
                        <th class="text-end">Value</th>
                        <th class="text-end">Weighted</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($opportunities as $opportunity)
                        <tr class="cursor-pointer {{ $opportunity->is_unread ? 'table-primary' : ($opportunity->next_follow_up_at && $opportunity->next_follow_up_at->isPast() && ! in_array($opportunity->status, ['won','lost'], true) ? 'table-warning' : '') }}" data-href="{{ route('tech.sales.show', $opportunity) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.sales.show', $opportunity) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">{{ $opportunity->opportunity_key }}</a>
                                <div class="text-muted small">{{ $opportunity->title }}</div>
                                @if($opportunity->is_unread)
                                    <div class="text-primary small fw-semibold">Unread prospect activity</div>
                                @endif
                            </td>
                            <td>{{ $opportunity->client?->name ?? '—' }}</td>
                            <td>
                                <span class="badge text-bg-light border">{{ $statuses[$opportunity->status]['label'] ?? $opportunity->status }}</span>
                            </td>
                            <td>{{ $opportunity->owner?->name ?? '—' }}</td>
                            <td>
                                @if($opportunity->next_follow_up_at)
                                    {{ $opportunity->next_follow_up_at->format('d.m.Y H:i') }}
                                    <div class="text-muted small">{{ $nextActions[$opportunity->next_follow_up_type] ?? $opportunity->next_follow_up_type }}</div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format((float) $opportunity->estimated_value_ex_vat, 2, ',', ' ') }}</td>
                            <td class="text-end">{{ number_format((float) $opportunity->weighted_value_ex_vat, 2, ',', ' ') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">No opportunities match this view.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($opportunities->hasPages())
                <div class="card-footer">{{ $opportunities->links() }}</div>
            @endif
        </div>
    </div>
@endsection

@section('rightbar')
    {{-- Sales summary keeps pipeline health visible beside the working list. --}}
    <div class="accordion mb-3" id="salesIndexSummaryAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="salesIndexSummaryHeader">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#salesIndexSummaryCollapse" aria-expanded="false" aria-controls="salesIndexSummaryCollapse">
                    Pipeline
                </button>
            </h2>
            <div id="salesIndexSummaryCollapse" class="accordion-collapse collapse" aria-labelledby="salesIndexSummaryHeader" data-bs-parent="#salesIndexSummaryAccordion">
                <div class="accordion-body">
                    <dl class="row mb-0">
                        <dt class="col-7">Open</dt>
                        <dd class="col-5 text-end">{{ $stats['open'] }}</dd>
                        <dt class="col-7">Won</dt>
                        <dd class="col-5 text-end">{{ $stats['won'] }}</dd>
                        <dt class="col-7">Unread</dt>
                        <dd class="col-5 text-end">{{ $stats['unread'] }}</dd>
                        <dt class="col-7">Due</dt>
                        <dd class="col-5 text-end">{{ $stats['due'] }}</dd>
                        <dt class="col-7">Weighted</dt>
                        <dd class="col-5 text-end">{{ number_format((float) $stats['weighted'], 0, ',', ' ') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
