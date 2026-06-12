@extends('layouts.default_tech')

@section('title', 'Marketing Campaigns')

@section('pageHeader')
    @php
        $hasCampaignPrerequisites = $listsCount > 0 && $marketingTemplatesCount > 0;
    @endphp
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">Marketing Campaigns</h1>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('tech.marketing.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                Marketing
            </a>
            @can('marketing.campaign.create')
                @if($hasCampaignPrerequisites)
                    <a href="{{ route('tech.marketing.campaigns.create') }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        New Campaign
                    </a>
                @elseif($listsCount === 0)
                    @can('marketing.list.manage')
                        <a href="{{ route('tech.marketing.lists.create') }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-people" aria-hidden="true"></i>
                            Create List First
                        </a>
                    @else
                        <button type="button" class="btn btn-sm btn-primary" disabled>New Campaign</button>
                    @endcan
                @elseif($marketingTemplatesCount === 0)
                    @can('email.template_manage')
                        <a href="{{ route('tech.admin.system.templatesManagement.email.index', ['scope' => 'marketing']) }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                            Create Template First
                        </a>
                    @else
                        <button type="button" class="btn btn-sm btn-primary" disabled>New Campaign</button>
                    @endcan
                @else
                    <button type="button" class="btn btn-sm btn-primary" disabled>New Campaign</button>
                @endif
            @endcan
        </div>
    </div>
@endsection

@section('content')
    @if($listsCount === 0 || $marketingTemplatesCount === 0)
        <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">Campaigns need a mailing list and an active marketing email template.</div>
                <div class="small">
                    @if($listsCount === 0)
                        Create a mailing list first. The campaign uses that resolved list as its recipient source.
                    @elseif($marketingTemplatesCount === 0)
                        Create or activate a marketing email template first. Campaign emails use Email-owned templates.
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Marketing campaign index -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Campaigns</span>
            <span class="badge text-bg-light border">{{ $campaigns->total() }} total</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>List</th>
                        <th>Status</th>
                        <th>Emails</th>
                        <th>Recipients</th>
                        <th>Sender</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($campaigns as $campaign)
                        <tr class="cursor-pointer" data-href="{{ route('tech.marketing.campaigns.show', $campaign) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.marketing.campaigns.show', $campaign) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">{{ $campaign->name }}</a>
                                <div class="small text-muted">{{ \Illuminate\Support\Str::limit($campaign->description, 90) ?: '—' }}</div>
                            </td>
                            <td>{{ $campaign->list?->name ?? '—' }}</td>
                            <td><span class="badge text-bg-{{ in_array($campaign->status, ['approved', 'active'], true) ? 'success' : 'light' }} border">{{ ucfirst($campaign->status) }}</span></td>
                            <td>{{ $campaign->emails_count }}</td>
                            <td>{{ $campaign->recipients_count }}</td>
                            <td>{{ $campaign->emailAccount?->address ?? 'Marketing default' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No marketing campaigns have been created.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($campaigns->hasPages())
            <div class="card-footer">{{ $campaigns->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection
