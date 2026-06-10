@extends('layouts.default_tech')

@section('title', $campaign->name)

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div>
            <h1 class="h4 mb-0">{{ $campaign->name }}</h1>
            <div class="small text-muted">{{ $campaign->description ?: 'Marketing campaign' }}</div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('tech.marketing.campaigns.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
                Campaigns
            </a>
            @can('marketing.campaign.approve')
                @if(in_array($campaign->status, ['draft', 'paused'], true))
                    <form method="POST" action="{{ route('tech.marketing.campaigns.approve', $campaign) }}" class="mb-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i>
                            Approve
                        </button>
                    </form>
                @endif
            @endcan
            @can('marketing.campaign.send')
                @if(in_array($campaign->status, ['approved', 'active'], true))
                    <form method="POST" action="{{ route('tech.marketing.campaigns.send-due', $campaign) }}" class="mb-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-send" aria-hidden="true"></i>
                            Send Due
                        </button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Marketing campaign detail -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success py-2">{{ session('status') }}</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Status</div>
                    <div><span class="badge text-bg-{{ in_array($campaign->status, ['approved', 'active'], true) ? 'success' : 'light' }} border">{{ ucfirst($campaign->status) }}</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Recipients</div>
                    <div class="fs-5 fw-semibold">{{ $campaign->recipients_count }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Events</div>
                    <div class="fs-5 fw-semibold">{{ $campaign->events_count }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body py-3">
                    <div class="small text-muted text-uppercase fw-semibold">Approved</div>
                    <div class="fw-semibold">{{ $campaign->approved_at?->format('Y-m-d H:i') ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    @if($interestSummary->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header">
                <span class="fw-semibold">Interest Signals</span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    @foreach($interestSummary as $interest)
                        <span class="badge text-bg-light border">
                            {{ $interest['name'] }}
                            <span class="text-muted ms-1">{{ $interest['count'] }}</span>
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Campaign Emails</span>
            <span class="badge text-bg-light border">{{ $campaign->emails_count }} total</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Order</th>
                        <th>Template</th>
                        <th>Subject Override</th>
                        <th>Delay</th>
                        <th>Scheduled</th>
                        <th>Status</th>
                        <th>Recipients</th>
                        @can('marketing.campaign.edit')
                            <th class="text-end">Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @foreach($campaign->emails as $email)
                        <tr>
                            <td style="width: 6rem;">
                                @can('marketing.campaign.edit')
                                    <input form="campaign-email-update-{{ $email->id }}" type="number" name="sequence_order" min="1" max="999" class="form-control form-control-sm" value="{{ old("emails.{$email->id}.sequence_order", $email->sequence_order) }}">
                                @else
                                    {{ $email->sequence_order }}
                                @endcan
                            </td>
                            <td>{{ $email->template?->name ?? '—' }}</td>
                            <td>
                                @can('marketing.campaign.edit')
                                    <input form="campaign-email-update-{{ $email->id }}" type="text" name="subject_override" class="form-control form-control-sm" value="{{ old("emails.{$email->id}.subject_override", $email->subject_override) }}" maxlength="255">
                                @else
                                    {{ $email->subject_override ?: '—' }}
                                @endcan
                            </td>
                            <td style="width: 8rem;">
                                @can('marketing.campaign.edit')
                                    <input form="campaign-email-update-{{ $email->id }}" type="number" name="delay_minutes" min="0" max="525600" class="form-control form-control-sm" value="{{ old("emails.{$email->id}.delay_minutes", $email->delay_minutes) }}">
                                @else
                                    {{ $email->delay_minutes }} min
                                @endcan
                            </td>
                            <td style="width: 12rem;">
                                @can('marketing.campaign.edit')
                                    <input form="campaign-email-update-{{ $email->id }}" type="datetime-local" name="scheduled_at" class="form-control form-control-sm" value="{{ old("emails.{$email->id}.scheduled_at", $email->scheduled_at?->format('Y-m-d\TH:i')) }}">
                                @else
                                    {{ $email->scheduled_at?->format('Y-m-d H:i') ?? ($campaign->starts_at?->format('Y-m-d H:i') ?? 'When approved') }}
                                @endcan
                            </td>
                            <td style="width: 8rem;">
                                @can('marketing.campaign.edit')
                                    <select form="campaign-email-update-{{ $email->id }}" name="status" class="form-select form-select-sm">
                                        <option value="active" @selected($email->status === 'active')>Active</option>
                                        <option value="inactive" @selected($email->status === 'inactive')>Inactive</option>
                                    </select>
                                @else
                                    <span class="badge text-bg-{{ $email->status === 'active' ? 'success' : 'light' }} border">{{ ucfirst($email->status) }}</span>
                                @endcan
                            </td>
                            <td>{{ $email->recipients_count }}</td>
                            @can('marketing.campaign.edit')
                                <td class="text-end">
                                    <form id="campaign-email-update-{{ $email->id }}" method="POST" action="{{ route('tech.marketing.campaigns.emails.update', [$campaign, $email]) }}" class="d-inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-save" aria-hidden="true"></i>
                                            Save
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('tech.marketing.campaigns.emails.destroy', [$campaign, $email]) }}" class="d-inline" onsubmit="return confirm('Remove this campaign email? Sent history will be kept if it exists.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @can('marketing.campaign.edit')
            <div class="card-body border-top">
                <form method="POST" action="{{ route('tech.marketing.campaigns.emails.store', $campaign) }}">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-lg-3">
                            <label for="email_template_id" class="form-label small text-muted mb-1">Template</label>
                            <select id="email_template_id" name="email_template_id" class="form-select form-select-sm @error('email_template_id') is-invalid @enderror" required>
                                <option value="">Select template</option>
                                @foreach($templates as $template)
                                    <option value="{{ $template->id }}" @selected((int) old('email_template_id') === $template->id)>{{ $template->name }}</option>
                                @endforeach
                            </select>
                            @error('email_template_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-lg-1">
                            <label for="sequence_order" class="form-label small text-muted mb-1">Order</label>
                            <input type="number" id="sequence_order" name="sequence_order" min="1" max="999" class="form-control form-control-sm @error('sequence_order') is-invalid @enderror" value="{{ old('sequence_order', $campaign->emails->max('sequence_order') + 1) }}" required>
                            @error('sequence_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-lg-2">
                            <label for="delay_minutes" class="form-label small text-muted mb-1">Delay minutes</label>
                            <input type="number" id="delay_minutes" name="delay_minutes" min="0" max="525600" class="form-control form-control-sm @error('delay_minutes') is-invalid @enderror" value="{{ old('delay_minutes', 0) }}" required>
                            @error('delay_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-lg-2">
                            <label for="scheduled_at" class="form-label small text-muted mb-1">Scheduled</label>
                            <input type="datetime-local" id="scheduled_at" name="scheduled_at" class="form-control form-control-sm @error('scheduled_at') is-invalid @enderror" value="{{ old('scheduled_at') }}">
                            @error('scheduled_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-lg-3">
                            <label for="subject_override" class="form-label small text-muted mb-1">Subject override</label>
                            <input type="text" id="subject_override" name="subject_override" class="form-control form-control-sm @error('subject_override') is-invalid @enderror" value="{{ old('subject_override') }}" maxlength="255">
                            @error('subject_override')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-lg-1 text-end">
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        @endcan
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span class="fw-semibold">Recipient Queue</span>
            <span class="badge text-bg-light border">{{ $recipients->total() }} total</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Due</th>
                        <th>Sent</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recipients as $recipient)
                        <tr>
                            <td>{{ $recipient->name ?: '—' }}</td>
                            <td>{{ $recipient->email }}</td>
                            <td>{{ $recipient->client?->name ?? '—' }}</td>
                            <td><span class="badge text-bg-{{ $recipient->status === 'sent' ? 'success' : ($recipient->status === 'failed' ? 'danger' : 'light') }} border">{{ ucfirst($recipient->status) }}</span></td>
                            <td>{{ $recipient->due_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>{{ $recipient->sent_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No recipients have been queued. Approve the campaign to create recipient queue entries.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($recipients->hasPages())
            <div class="card-footer">{{ $recipients->links() }}</div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
    <x-card.default title="Campaign Settings">
        <dl class="row small mb-0">
            <dt class="col-6">List</dt>
            <dd class="col-6 text-end">{{ $campaign->list?->name ?? '—' }}</dd>
            <dt class="col-6">Sender</dt>
            <dd class="col-6 text-end">{{ $campaign->emailAccount?->address ?? 'Marketing default' }}</dd>
            <dt class="col-6">Batch</dt>
            <dd class="col-6 text-end">{{ $campaign->batch_size ?: $settings['default_batch_size'] }}</dd>
            <dt class="col-6">Tracking</dt>
            <dd class="col-6 text-end">{{ $campaign->track_opens ? 'Open' : 'No open' }} / {{ $campaign->track_clicks ? 'Click' : 'No click' }}</dd>
        </dl>
    </x-card.default>
@endsection
