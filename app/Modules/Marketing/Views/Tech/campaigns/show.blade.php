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
    @if($errors->any())
        <div class="alert alert-danger py-2">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
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

    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
        <h2 class="h5 mb-0">Campaign Emails</h2>
        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-light border">{{ $campaign->emails_count }} total</span>
            @can('marketing.campaign.edit')
                <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#campaignEmailCreatePanel" aria-expanded="false" aria-controls="campaignEmailCreatePanel">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    New Email
                </button>
            @endcan
        </div>
    </div>

    @can('marketing.campaign.edit')
        <div class="collapse mb-3" id="campaignEmailCreatePanel">
            <div class="card" data-email-workspace data-email-id="new">
                <div class="card-header">
                    <span class="fw-semibold">New Campaign Email</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-xl-7">
                            <form id="campaign-email-create" method="POST" action="{{ route('tech.marketing.campaigns.emails.store', $campaign) }}" data-email-create-form>
                                @csrf
                                <div class="row g-3">
                                    <div class="col-lg-5">
                                        <label for="email_template_id" class="form-label">Start Template</label>
                                        <select id="email_template_id" name="email_template_id" class="form-select form-select-sm @error('email_template_id') is-invalid @enderror" required data-template-select>
                                            <option value="">Select template</option>
                                            @foreach($templates as $template)
                                                <option value="{{ $template->id }}" @selected((int) old('email_template_id') === $template->id)>{{ $template->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('email_template_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-lg-3">
                                        <label for="email_name" class="form-label">Email Name</label>
                                        <input type="text" id="email_name" name="email_name" class="form-control form-control-sm @error('email_name') is-invalid @enderror" value="{{ old('email_name') }}" maxlength="255" data-email-name>
                                        @error('email_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-lg-4">
                                        <label for="email_subject" class="form-label">Email Subject</label>
                                        <input type="text" id="email_subject" name="email_subject" class="form-control form-control-sm @error('email_subject') is-invalid @enderror" value="{{ old('email_subject') }}" maxlength="255" required data-email-subject>
                                        @error('email_subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-lg-3">
                                        <label for="sequence_order" class="form-label">Order</label>
                                        <input type="number" id="sequence_order" name="sequence_order" min="1" max="999" class="form-control form-control-sm @error('sequence_order') is-invalid @enderror" value="{{ old('sequence_order', $campaign->emails->max('sequence_order') + 1) }}" required>
                                        @error('sequence_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-lg-3">
                                        <label for="delay_minutes" class="form-label">Delay Minutes</label>
                                        <input type="number" id="delay_minutes" name="delay_minutes" min="0" max="525600" class="form-control form-control-sm @error('delay_minutes') is-invalid @enderror" value="{{ old('delay_minutes', 0) }}" required>
                                        @error('delay_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-12">
                                        <label for="body_html" class="form-label">HTML Body</label>
                                        <textarea id="body_html" name="body_html" rows="9" class="form-control form-control-sm font-monospace @error('body_html') is-invalid @enderror" data-email-html>{{ old('body_html') }}</textarea>
                                        @error('body_html')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-12">
                                        <label for="body_text" class="form-label">Plain Text Body</label>
                                        <textarea id="body_text" name="body_text" rows="5" class="form-control form-control-sm font-monospace @error('body_text') is-invalid @enderror" data-email-text>{{ old('body_text') }}</textarea>
                                        @error('body_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    @if($aiDraftAvailable)
                                        <div class="col-12">
                                            <label for="ai_prompt_new" class="form-label">AI Prompt</label>
                                            <div class="input-group input-group-sm">
                                                <textarea id="ai_prompt_new" class="form-control" rows="2" data-email-ai-prompt></textarea>
                                                <button type="button" class="btn btn-outline-primary" data-email-ai-button data-ai-url="{{ route('tech.marketing.campaigns.emails.ai-draft', $campaign) }}">
                                                    <i class="bi bi-stars" aria-hidden="true"></i>
                                                    AI Draft
                                                </button>
                                            </div>
                                            <div class="form-text" data-email-ai-status></div>
                                        </div>
                                    @endif
                                    <div class="col-12 text-end">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                            Add Email
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="col-xl-5">
                            <div class="border rounded p-2 h-100">
                                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                    <span class="fw-semibold">Preview</span>
                                    <span class="small text-muted text-truncate" data-email-preview-subject></span>
                                </div>
                                <iframe class="w-100 border rounded bg-white" style="height: 31rem;" sandbox data-email-preview title="New email preview"></iframe>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endcan

    <div class="accordion mb-3" id="campaignEmailAccordion">
        @forelse($campaign->emails as $email)
            <div class="card mb-2" data-email-workspace data-email-id="{{ $email->id }}">
                <div class="card-header p-0">
                    <button class="btn btn-link text-start text-decoration-none w-100 p-3" type="button" data-bs-toggle="collapse" data-bs-target="#campaignEmailPanel-{{ $email->id }}" aria-expanded="false" aria-controls="campaignEmailPanel-{{ $email->id }}">
                        <div class="d-flex align-items-center justify-content-between gap-3">
                            <div class="flex-grow-1 overflow-hidden" style="min-width: 0;">
                                <div class="fw-semibold text-body text-truncate">#{{ $email->sequence_order }} {{ $email->displayName() }}</div>
                                <div class="small text-muted text-truncate">{{ $email->effectiveSubject() ?? 'No subject' }}</div>
                            </div>
                            <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                <span class="badge text-bg-{{ $email->status === 'active' ? 'success' : 'light' }} border">{{ ucfirst($email->status) }}</span>
                                <span class="badge text-bg-light border">{{ $email->delay_minutes }} min</span>
                                <span class="badge text-bg-light border">{{ $email->recipients_count }} recipients</span>
                                <span class="badge text-bg-light border">{{ $email->sourceTemplateName() ?? 'No template' }}</span>
                            </div>
                        </div>
                    </button>
                </div>
                <div id="campaignEmailPanel-{{ $email->id }}" class="collapse" data-bs-parent="#campaignEmailAccordion">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-xl-7">
                                @can('marketing.campaign.edit')
                                    <form id="campaign-email-update-{{ $email->id }}" method="POST" action="{{ route('tech.marketing.campaigns.emails.update', [$campaign, $email]) }}" data-email-edit-form>
                                        @csrf
                                        @method('PUT')
                                        <div class="row g-3">
                                            <div class="col-lg-4">
                                                <label for="email_name_{{ $email->id }}" class="form-label">Email Name</label>
                                                <input type="text" id="email_name_{{ $email->id }}" name="email_name" class="form-control form-control-sm" value="{{ old('email_name', $email->displayName()) }}" maxlength="255" data-email-name>
                                            </div>
                                            <div class="col-lg-8">
                                                <label for="email_subject_{{ $email->id }}" class="form-label">Email Subject</label>
                                                <input type="text" id="email_subject_{{ $email->id }}" name="email_subject" class="form-control form-control-sm" value="{{ old('email_subject', $email->effectiveSubject()) }}" maxlength="255" required data-email-subject>
                                            </div>
                                            <div class="col-lg-3">
                                                <label for="sequence_order_{{ $email->id }}" class="form-label">Order</label>
                                                <input type="number" id="sequence_order_{{ $email->id }}" name="sequence_order" min="1" max="999" class="form-control form-control-sm" value="{{ old('sequence_order', $email->sequence_order) }}" required>
                                            </div>
                                            <div class="col-lg-3">
                                                <label for="delay_minutes_{{ $email->id }}" class="form-label">Delay Minutes</label>
                                                <input type="number" id="delay_minutes_{{ $email->id }}" name="delay_minutes" min="0" max="525600" class="form-control form-control-sm" value="{{ old('delay_minutes', $email->delay_minutes) }}" required>
                                            </div>
                                            <div class="col-lg-3">
                                                <label for="status_{{ $email->id }}" class="form-label">Status</label>
                                                <select id="status_{{ $email->id }}" name="status" class="form-select form-select-sm">
                                                    <option value="active" @selected($email->status === 'active')>Active</option>
                                                    <option value="inactive" @selected($email->status === 'inactive')>Inactive</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label for="body_html_{{ $email->id }}" class="form-label">HTML Body</label>
                                                <textarea id="body_html_{{ $email->id }}" name="body_html" rows="9" class="form-control form-control-sm font-monospace" data-email-html>{{ old('body_html', $email->effectiveBodyHtml()) }}</textarea>
                                            </div>
                                            <div class="col-12">
                                                <label for="body_text_{{ $email->id }}" class="form-label">Plain Text Body</label>
                                                <textarea id="body_text_{{ $email->id }}" name="body_text" rows="5" class="form-control form-control-sm font-monospace" data-email-text>{{ old('body_text', $email->effectiveBodyText()) }}</textarea>
                                            </div>
                                            @if($aiDraftAvailable)
                                                <div class="col-12">
                                                    <label for="ai_prompt_{{ $email->id }}" class="form-label">AI Prompt</label>
                                                    <div class="input-group input-group-sm">
                                                        <textarea id="ai_prompt_{{ $email->id }}" class="form-control" rows="2" data-email-ai-prompt></textarea>
                                                        <button type="button" class="btn btn-outline-primary" data-email-ai-button data-ai-url="{{ route('tech.marketing.campaigns.emails.ai-draft', $campaign) }}">
                                                            <i class="bi bi-stars" aria-hidden="true"></i>
                                                            AI Draft
                                                        </button>
                                                    </div>
                                                    <div class="form-text" data-email-ai-status></div>
                                                </div>
                                            @endif
                                            <div class="col-12 d-flex justify-content-end gap-2">
                                                <button type="submit" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-save" aria-hidden="true"></i>
                                                    Save
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                @else
                                    <dl class="row small mb-0">
                                        <dt class="col-md-3">Subject</dt>
                                        <dd class="col-md-9">{{ $email->effectiveSubject() ?? '—' }}</dd>
                                        <dt class="col-md-3">Delay</dt>
                                        <dd class="col-md-9">{{ $email->delay_minutes }} min</dd>
                                        <dt class="col-md-3">Template</dt>
                                        <dd class="col-md-9">{{ $email->sourceTemplateName() ?? '—' }}</dd>
                                    </dl>
                                @endcan
                            </div>
                            <div class="col-xl-5">
                                <div class="border rounded p-2 mb-3">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <span class="fw-semibold">Preview</span>
                                        <span class="small text-muted text-truncate" data-email-preview-subject></span>
                                    </div>
                                    <iframe class="w-100 border rounded bg-white" style="height: 31rem;" sandbox data-email-preview title="{{ $email->displayName() }} preview"></iframe>
                                </div>
                                @can('marketing.campaign.edit')
                                    <form method="POST" action="{{ route('tech.marketing.campaigns.emails.test-send', [$campaign, $email]) }}" class="border rounded p-2 mb-3" data-email-test-form>
                                        @csrf
                                        <input type="hidden" name="email_name" data-email-test-hidden="email_name">
                                        <input type="hidden" name="email_subject" data-email-test-hidden="email_subject">
                                        <input type="hidden" name="body_html" data-email-test-hidden="body_html">
                                        <input type="hidden" name="body_text" data-email-test-hidden="body_text">
                                        <label for="test_to_email_{{ $email->id }}" class="form-label">Test Email</label>
                                        <div class="input-group input-group-sm">
                                            <input type="email" id="test_to_email_{{ $email->id }}" name="test_to_email" class="form-control" value="{{ old('test_to_email', auth()->user()?->email) }}" required>
                                            <input type="text" name="test_to_name" class="form-control" value="{{ old('test_to_name', auth()->user()?->name) }}">
                                            <button type="submit" class="btn btn-outline-primary">
                                                <i class="bi bi-send" aria-hidden="true"></i>
                                                Send Test
                                            </button>
                                        </div>
                                    </form>
                                    <form method="POST" action="{{ route('tech.marketing.campaigns.emails.destroy', [$campaign, $email]) }}" class="text-end mb-0" onsubmit="return confirm('Remove this campaign email? Sent history will be kept if it exists.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                            Remove
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="card mb-3">
                <div class="card-body text-center text-muted py-4">No campaign emails have been created.</div>
            </div>
        @endforelse
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

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const templateSnapshots = {!! \Illuminate\Support\Js::from($templateSnapshots) !!};
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}';

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function (char) {
                    return {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;',
                    }[char];
                });
            }

            function previewDocument(html) {
                const content = String(html || '').trim();

                if (content.toLowerCase().includes('<html')) {
                    return content;
                }

                return `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { margin: 0; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #111827; }
    main { max-width: 680px; margin: 24px auto; background: #ffffff; border: 1px solid #e5e7eb; padding: 24px; }
    a { color: #0d6efd; }
  </style>
</head>
<body><main>${content || '<p style="color:#6c757d;">No HTML body.</p>'}</main></body>
</html>`;
            }

            function field(scope, selector) {
                return scope.querySelector(selector);
            }

            function updatePreview(scope) {
                const iframe = field(scope, '[data-email-preview]');
                const html = field(scope, '[data-email-html]')?.value || '';
                const subject = field(scope, '[data-email-subject]')?.value || '';
                const subjectLabel = field(scope, '[data-email-preview-subject]');

                if (iframe) {
                    iframe.srcdoc = previewDocument(html);
                }

                if (subjectLabel) {
                    subjectLabel.textContent = subject;
                }
            }

            function setEditorValues(scope, values) {
                const pairs = {
                    '[data-email-name]': values.email_name ?? values.name,
                    '[data-email-subject]': values.email_subject ?? values.subject,
                    '[data-email-html]': values.body_html,
                    '[data-email-text]': values.body_text,
                };

                Object.entries(pairs).forEach(function ([selector, value]) {
                    const input = field(scope, selector);

                    if (input && value !== undefined && value !== null) {
                        input.value = value;
                    }
                });

                updatePreview(scope);
            }

            document.querySelectorAll('[data-email-workspace]').forEach(function (scope) {
                scope.querySelectorAll('[data-email-name], [data-email-subject], [data-email-html], [data-email-text]').forEach(function (input) {
                    input.addEventListener('input', function () {
                        updatePreview(scope);
                    });
                });

                updatePreview(scope);
            });

            document.querySelectorAll('[data-template-select]').forEach(function (select) {
                select.addEventListener('change', function () {
                    const scope = select.closest('[data-email-workspace]');
                    const snapshot = templateSnapshots[String(select.value)];

                    if (!scope || !snapshot) {
                        return;
                    }

                    setEditorValues(scope, {
                        email_name: snapshot.name,
                        email_subject: snapshot.subject,
                        body_html: snapshot.body_html,
                        body_text: snapshot.body_text,
                    });
                });
            });

            document.querySelectorAll('[data-email-test-form]').forEach(function (form) {
                form.addEventListener('submit', function () {
                    const scope = form.closest('[data-email-workspace]');

                    if (!scope) {
                        return;
                    }

                    const values = {
                        email_name: field(scope, '[data-email-name]')?.value || '',
                        email_subject: field(scope, '[data-email-subject]')?.value || '',
                        body_html: field(scope, '[data-email-html]')?.value || '',
                        body_text: field(scope, '[data-email-text]')?.value || '',
                    };

                    Object.entries(values).forEach(function ([key, value]) {
                        const input = form.querySelector(`[data-email-test-hidden="${key}"]`);

                        if (input) {
                            input.value = value;
                        }
                    });
                });
            });

            document.querySelectorAll('[data-email-ai-button]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    const scope = button.closest('[data-email-workspace]');
                    const prompt = field(scope, '[data-email-ai-prompt]')?.value.trim() || '';
                    const status = field(scope, '[data-email-ai-status]');
                    const originalHtml = button.innerHTML;

                    if (!prompt) {
                        if (status) {
                            status.className = 'form-text text-danger';
                            status.textContent = 'Prompt is required.';
                        }

                        return;
                    }

                    button.disabled = true;
                    button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Drafting';

                    if (status) {
                        status.className = 'form-text text-muted';
                        status.textContent = 'AI is drafting.';
                    }

                    try {
                        const response = await fetch(button.dataset.aiUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({
                                campaign_email_id: scope.dataset.emailId === 'new' ? null : scope.dataset.emailId,
                                prompt: prompt,
                                email_name: field(scope, '[data-email-name]')?.value || '',
                                email_subject: field(scope, '[data-email-subject]')?.value || '',
                                body_html: field(scope, '[data-email-html]')?.value || '',
                                body_text: field(scope, '[data-email-text]')?.value || '',
                            }),
                        });
                        const payload = await response.json().catch(function () {
                            return {};
                        });

                        if (!response.ok) {
                            throw new Error(payload.message || 'AI draft failed.');
                        }

                        setEditorValues(scope, payload);

                        if (status) {
                            status.className = 'form-text text-success';
                            status.textContent = 'Draft inserted.';
                        }
                    } catch (error) {
                        if (status) {
                            status.className = 'form-text text-danger';
                            status.textContent = error.message || 'AI draft failed.';
                        }
                    } finally {
                        button.disabled = false;
                        button.innerHTML = originalHtml;
                    }
                });
            });
        });
    </script>
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
