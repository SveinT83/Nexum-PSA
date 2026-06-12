@extends('layouts.default_tech')

@section('title', 'Marketing Lists')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">Marketing Lists</h1>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('tech.marketing.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                Marketing
            </a>
            @can('marketing.list.manage')
                <a href="{{ route('tech.marketing.lists.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    New List
                </a>
            @endcan
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Marketing list index -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Recipient Lists</span>
            <span class="badge text-bg-light border">{{ $lists->total() }} total</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Audience</th>
                        <th>Members</th>
                        <th>Last resolved</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lists as $list)
                        <tr class="cursor-pointer" data-href="{{ route('tech.marketing.lists.show', $list) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.marketing.lists.show', $list) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">{{ $list->name }}</a>
                                <div class="small text-muted">{{ \Illuminate\Support\Str::limit($list->description, 90) ?: '—' }}</div>
                            </td>
                            <td><span class="badge text-bg-light border">{{ str($list->audience_type)->replace('_', ' ')->title() }}</span></td>
                            <td>{{ $list->members_count }}</td>
                            <td>{{ $list->last_resolved_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No marketing lists have been created.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($lists->hasPages())
            <div class="card-footer">{{ $lists->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
    <x-card.default title="Consent Policy">
        <dl class="row small mb-0">
            <dt class="col-6">Consent</dt>
            <dd class="col-6 text-end">{{ $settings['consent_mode'] === 'opt_out' ? 'Opt-out' : 'Explicit opt-in' }}</dd>
            <dt class="col-6">Unsubscribe</dt>
            <dd class="col-6 text-end">{{ $settings['unsubscribe_mode'] === 'all_marketing' ? 'All marketing' : 'Category' }}</dd>
            <dt class="col-6">Batch size</dt>
            <dd class="col-6 text-end">{{ $settings['default_batch_size'] }}</dd>
            <dt class="col-6">Interval</dt>
            <dd class="col-6 text-end">{{ $settings['default_send_interval_minutes'] }} min</dd>
        </dl>
    </x-card.default>
@endsection
