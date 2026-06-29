@extends('layouts.default_tech')

@section('title', 'Marketing')

@section('pageHeader')
    @php
        $hasCampaignPrerequisites = $dashboard['lists_total'] > 0 && $dashboard['templates_active'] > 0;
    @endphp
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="h4 mb-0">Marketing</h1>
        <div class="d-flex align-items-center gap-2">
            @can('marketing.campaign.create')
                @if($hasCampaignPrerequisites)
                    <a href="{{ route('tech.marketing.campaigns.create') }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        New Campaign
                    </a>
                @elseif($dashboard['lists_total'] === 0)
                    @can('marketing.list.manage')
                        <a href="{{ route('tech.marketing.lists.create') }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-people" aria-hidden="true"></i>
                            Create List First
                        </a>
                    @else
                        <button type="button" class="btn btn-sm btn-primary" disabled>New Campaign</button>
                    @endcan
                @elseif($dashboard['templates_active'] === 0)
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
            @can('marketing.list.manage')
                @if($dashboard['lists_total'] > 0)
                    <a href="{{ route('tech.marketing.lists.create') }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-people" aria-hidden="true"></i>
                        New List
                    </a>
                @endif
            @endcan
        </div>
    </div>
@endsection

@section('content')
    @php
        $statusBadge = function (?string $status): string {
            return match ($status) {
                'active', 'approved', 'sent' => 'success',
                'pending', 'draft' => 'warning',
                'paused' => 'secondary',
                'failed' => 'danger',
                default => 'light',
            };
        };

        $statCards = [
            [
                'label' => 'Active Campaigns',
                'value' => $dashboard['campaigns_active'] + $dashboard['campaigns_approved'],
                'detail' => $dashboard['campaigns_draft'].' drafts',
                'icon' => 'bi-megaphone',
            ],
            [
                'label' => 'Due Now',
                'value' => $dashboard['recipients_due'],
                'detail' => $dashboard['recipients_pending'].' pending recipients',
                'icon' => 'bi-send',
            ],
            [
                'label' => 'Sent',
                'value' => $dashboard['recipients_sent'],
                'detail' => $dashboard['opens'].' opens · '.$dashboard['clicks'].' clicks',
                'icon' => 'bi-envelope-check',
            ],
            [
                'label' => 'Recipients',
                'value' => $dashboard['members_total'],
                'detail' => $dashboard['lists_total'].' mailing lists',
                'icon' => 'bi-people',
            ],
        ];
    @endphp

    <!-- ------------------------------------------------- -->
    <!-- Email marketing dashboard -->
    <!-- ------------------------------------------------- -->
    <div class="row g-3 mb-3">
        @foreach($statCards as $card)
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body py-3">
                        <div class="d-flex align-items-center justify-content-between gap-3">
                            <div>
                                <div class="small text-muted text-uppercase fw-semibold">{{ $card['label'] }}</div>
                                <div class="fs-4 fw-semibold">{{ number_format($card['value']) }}</div>
                            </div>
                            <i class="bi {{ $card['icon'] }} fs-4 text-primary" aria-hidden="true"></i>
                        </div>
                        <div class="small text-muted mt-1">{{ $card['detail'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if($dashboard['lists_total'] === 0 || $dashboard['templates_active'] === 0)
        <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
            <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">Campaign setup needs a mailing list and an active marketing email template.</div>
                <div class="small">
                    @if($dashboard['lists_total'] === 0)
                        Create a mailing list first so the campaign has recipients.
                    @elseif($dashboard['templates_active'] === 0)
                        Create or activate a marketing email template before creating a campaign.
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <span class="fw-semibold">Campaigns</span>
                    <a href="{{ route('tech.marketing.campaigns.index') }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        Open
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Audience Lists</th>
                                <th class="text-end">Emails</th>
                                <th class="text-end">Audience Recipients</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentCampaigns as $campaign)
                                @php($audienceLists = $campaign->audienceLists())
                                @php($audienceRecipientsCount = (int) ($campaign->audience_recipients_count ?? $campaign->recipients_count))
                                <tr class="cursor-pointer" data-href="{{ route('tech.marketing.campaigns.show', $campaign) }}" onclick="window.location.href = this.dataset.href">
                                    <td>
                                        <a href="{{ route('tech.marketing.campaigns.show', $campaign) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">{{ $campaign->name }}</a>
                                        <div class="small text-muted">{{ $campaign->emailAccount?->address ?? 'Marketing default sender' }}</div>
                                    </td>
                                    <td><span class="badge text-bg-{{ $statusBadge($campaign->status) }} border">{{ ucfirst($campaign->status) }}</span></td>
                                    <td>
                                        @forelse($audienceLists as $list)
                                            <span class="badge text-bg-light border">{{ $list->name }}</span>
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                    </td>
                                    <td class="text-end">{{ $campaign->emails_count }}</td>
                                    <td class="text-end">{{ number_format($audienceRecipientsCount) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No marketing campaigns have been created.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <span class="fw-semibold">Sending Queue</span>
                    <span class="badge text-bg-light border">{{ number_format($dashboard['recipients_pending']) }} pending</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Recipient</th>
                                <th>Campaign</th>
                                <th class="text-end">Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($dueRecipients as $recipient)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $recipient->name ?: $recipient->email }}</div>
                                        <div class="small text-muted">{{ $recipient->email }}</div>
                                    </td>
                                    <td>
                                        <a href="{{ route('tech.marketing.campaigns.show', $recipient->campaign) }}" class="text-decoration-none">{{ $recipient->campaign?->name ?? '—' }}</a>
                                        <div class="small text-muted">{{ $recipient->campaignEmail?->template?->name ?? 'Campaign email' }}</div>
                                    </td>
                                    <td class="text-end text-nowrap">{{ $recipient->due_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">No pending campaign recipients.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Tracking Activity</span>
            <span class="badge text-bg-light border">{{ number_format($dashboard['unsubscribes']) }} unsubscribes</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Event</th>
                        <th>Campaign</th>
                        <th>Recipient</th>
                        <th>URL</th>
                        <th class="text-end">Time</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentEvents as $event)
                        <tr>
                            <td><span class="badge text-bg-light border">{{ str($event->type)->replace('_', ' ')->title() }}</span></td>
                            <td>{{ $event->campaign?->name ?? '—' }}</td>
                            <td>{{ $event->recipient?->email ?? '—' }}</td>
                            <td class="text-truncate" style="max-width: 22rem;">{{ $event->url ?: '—' }}</td>
                            <td class="text-end text-nowrap">{{ $event->occurred_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No marketing tracking events yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
    <!-- ------------------------------------------------- -->
    <!-- Marketing navigation -->
    <!-- ------------------------------------------------- -->
    <div class="card mt-3 mb-3">
        <div class="card-header">
            <span class="fw-semibold">Email Marketing</span>
        </div>
        <div class="list-group list-group-flush">
            <a href="{{ route('tech.marketing.campaigns.index') }}" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between gap-2">
                <span><i class="bi bi-megaphone me-2" aria-hidden="true"></i>Campaigns</span>
                <span class="badge text-bg-light border">{{ number_format($dashboard['campaigns_total']) }}</span>
            </a>
            <a href="{{ route('tech.marketing.lists.index') }}" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between gap-2">
                <span><i class="bi bi-people me-2" aria-hidden="true"></i>Mailing Lists</span>
                <span class="badge text-bg-light border">{{ number_format($dashboard['lists_total']) }}</span>
            </a>
            @can('email.template_manage')
                @if(Route::has('tech.admin.system.templatesManagement.email.index'))
                    <a href="{{ route('tech.admin.system.templatesManagement.email.index', ['scope' => 'marketing']) }}" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between gap-2">
                        <span><i class="bi bi-pencil-square me-2" aria-hidden="true"></i>Email Templates</span>
                        <span class="badge text-bg-light border">{{ number_format($dashboard['templates_active']) }}</span>
                    </a>
                @endif
            @endcan
            @can('email.account_manage')
                @if(Route::has('tech.admin.settings.email.accounts'))
                    <a href="{{ route('tech.admin.settings.email.accounts') }}" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between gap-2">
                        <span><i class="bi bi-hdd-network me-2" aria-hidden="true"></i>Sender Accounts</span>
                        <span class="badge text-bg-light border">{{ number_format($dashboard['marketing_sender_accounts']) }}</span>
                    </a>
                @endif
            @endcan
            @can('marketing.settings.manage')
                <a href="{{ route('tech.admin.settings.marketing') }}" class="list-group-item list-group-item-action">
                    <i class="bi bi-sliders me-2" aria-hidden="true"></i>Marketing Settings
                </a>
            @endcan
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <span class="fw-semibold">Sending Defaults</span>
        </div>
        <div class="card-body">
            <dl class="row small mb-0">
                <dt class="col-6">Sender</dt>
                <dd class="col-6 text-end">{{ $marketingDefaultAccount?->address ?? 'Not configured' }}</dd>
                <dt class="col-6">Batch size</dt>
                <dd class="col-6 text-end">{{ number_format($settings['default_batch_size']) }}</dd>
                <dt class="col-6">Interval</dt>
                <dd class="col-6 text-end">{{ $settings['default_send_interval_minutes'] }} min</dd>
                <dt class="col-6">Tracking</dt>
                <dd class="col-6 text-end">{{ $settings['open_tracking_enabled'] || $settings['click_tracking_enabled'] ? 'Enabled' : 'Disabled' }}</dd>
            </dl>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <span class="fw-semibold">Consent Policy</span>
        </div>
        <div class="card-body">
            <dl class="row small mb-0">
                <dt class="col-6">Consent</dt>
                <dd class="col-6 text-end">{{ $settings['consent_mode'] === 'opt_out' ? 'Opt-out' : 'Opt-in' }}</dd>
                <dt class="col-6">Unsubscribe</dt>
                <dd class="col-6 text-end">{{ $settings['unsubscribe_mode'] === 'all_marketing' ? 'All marketing' : 'Category' }}</dd>
                <dt class="col-6">Contract clients</dt>
                <dd class="col-6 text-end">{{ $settings['active_contract_clients_eligible'] ? 'Eligible' : 'Excluded' }}</dd>
            </dl>
        </div>
    </div>
@endsection
