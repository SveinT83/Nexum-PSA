@extends('layouts.default_tech')

@section('title', $list->name)

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h4 mb-0">{{ $list->name }}</h1>
            <div class="small text-muted">{{ $list->description ?: 'Marketing recipient list' }}</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('tech.marketing.lists.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                Lists
            </a>
            @can('marketing.list.manage')
                <form method="POST" action="{{ route('tech.marketing.lists.refresh', $list) }}" class="mb-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                        Refresh
                    </button>
                </form>
            @endcan
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Marketing list members -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Members</div>
                    <div class="fs-4 fw-semibold">{{ $list->members_count }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Audience</div>
                    <div class="fw-semibold">{{ str($list->audience_type)->replace('_', ' ')->title() }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Last Resolved</div>
                    <div class="fw-semibold">{{ $list->last_resolved_at?->format('Y-m-d H:i') ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    @php
        $criteria = $list->segment_criteria ?? [];
        $contactTagIds = collect($criteria['contact_tag_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
        $clientTagIds = collect($criteria['client_tag_ids'] ?? [])->map(fn ($id) => (int) $id)->filter();
    @endphp

    @if($contactTagIds->isNotEmpty() || $clientTagIds->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header">
                <span class="fw-semibold">Active Segments</span>
            </div>
            <div class="card-body d-grid gap-3">
                @if($contactTagIds->isNotEmpty())
                    <div>
                        <div class="small text-muted text-uppercase fw-semibold mb-2">Contact tags</div>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($contactTagIds as $tagId)
                                @if($segmentTags->has($tagId))
                                    <span class="badge text-bg-light border">{{ $segmentTags->get($tagId)->name }}</span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
                @if($clientTagIds->isNotEmpty())
                    <div>
                        <div class="small text-muted text-uppercase fw-semibold mb-2">Client tags</div>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach($clientTagIds as $tagId)
                                @if($segmentTags->has($tagId))
                                    <span class="badge text-bg-light border">{{ $segmentTags->get($tagId)->name }}</span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Recipients</span>
            <span class="badge text-bg-light border">{{ $members->total() }} resolved</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Client</th>
                        <th>Source</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($members as $member)
                        <tr>
                            <td>{{ $member->name ?: '—' }}</td>
                            <td><a href="mailto:{{ $member->email }}" class="text-decoration-none">{{ $member->email }}</a></td>
                            <td>{{ $member->client?->name ?? '—' }}</td>
                            <td><span class="badge text-bg-light border">{{ str($member->source_type)->replace('_', ' ')->title() }}</span></td>
                            <td><span class="badge text-bg-success">{{ ucfirst($member->status) }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No recipients are currently eligible for this list.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($members->hasPages())
            <div class="card-footer">{{ $members->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
    <x-card.default title="Consent Scope">
        <dl class="row small mb-0">
            <dt class="col-6">Category</dt>
            <dd class="col-6 text-end">{{ $list->consentCategory?->name ?? 'General' }}</dd>
            <dt class="col-6">Consent</dt>
            <dd class="col-6 text-end">{{ $settings['consent_mode'] === 'opt_out' ? 'Opt-out' : 'Explicit opt-in' }}</dd>
            <dt class="col-6">Unsubscribe</dt>
            <dd class="col-6 text-end">{{ $settings['unsubscribe_mode'] === 'all_marketing' ? 'All marketing' : 'Category' }}</dd>
        </dl>
    </x-card.default>
@endsection
