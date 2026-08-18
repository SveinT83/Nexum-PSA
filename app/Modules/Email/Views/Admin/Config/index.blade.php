@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Email Sync & Cache Settings</h1>
        <div class="d-flex gap-2">
            <button type="submit" form="config-form" class="btn btn-primary">Save Settings</button>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="email" />
@endsection

@section('content')
<div class="container-fluid">
    <form id="config-form" action="{{ route('tech.admin.settings.email.config.update') }}" method="POST">
        @csrf
        @php
            $legacyCleanupEnabled = (string) ($config['delete_on_success'] ?? '0') === '1';
            $providerDeletionReconciliationEnabled = (string) ($config['provider_deletion_reconciliation_enabled'] ?? '0') === '1';
            $trustedAuthservConfigured = trim((string) ($config['trusted_authserv_ids'] ?? '')) !== '';
            $trustedHopsConfigured = trim((string) ($config['trusted_receiving_hops'] ?? '')) !== '';
            $trustedTrustConfigured = $trustedAuthservConfigured && $trustedHopsConfigured;
            $trustedTrustExpanded = $trustedAuthservConfigured
                || $trustedHopsConfigured
                || $errors->has('trusted_authserv_ids')
                || $errors->has('trusted_receiving_hops');
        @endphp
        <div class="row">
            <div class="col-lg-8">
                <!-- 1. Provider Sync -->
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Provider Sync</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="poll_interval" class="form-label">Poll interval</label>
                                <select name="poll_interval" id="poll_interval" class="form-select">
                                    @foreach([1, 5, 15, 30] as $min)
                                        <option value="{{ $min }}" {{ (isset($config['poll_interval']) && $config['poll_interval'] == $min) ? 'selected' : '' }}>{{ $min }} min</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="concurrency" class="form-label">Concurrent account fetches</label>
                                <select name="concurrency" id="concurrency" class="form-select">
                                    @foreach([1, 2, 4, 8] as $val)
                                        <option value="{{ $val }}" {{ (isset($config['concurrency']) && $config['concurrency'] == $val) ? 'selected' : '' }}>{{ $val }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="batch_size" class="form-label">New messages per folder poll</label>
                                <input type="number" name="batch_size" id="batch_size" class="form-control" value="{{ $config['batch_size'] ?? 20 }}" min="1">
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" name="pause_ingest" id="pause_ingest" value="1" {{ (isset($config['pause_ingest']) && $config['pause_ingest'] == '1') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="pause_ingest">Pause provider sync</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Local Cache And Legacy Cleanup -->
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Local Cache & Legacy Cleanup</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="delete_on_success" id="delete_on_success" value="1" {{ (isset($config['delete_on_success']) && $config['delete_on_success'] == '1') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="delete_on_success">Legacy server cleanup after successful Ticket-ingest import</label>
                                    <div class="form-text">
                                        Normal IMAP client sync keeps provider mail on the server. This switch only affects
                                        accounts set to <strong>Use legacy global cleanup switch</strong>.
                                    </div>
                                </div>
                                @if($legacyCleanupEnabled)
                                    <div class="alert alert-warning py-2 small mb-0" role="alert">
                                        Legacy cleanup is enabled. Ticket-ingress imports for accounts using the legacy
                                        global cleanup policy may remove newly imported INBOX mail from the provider after
                                        local storage.
                                    </div>
                                @endif
                            </div>
                            <div class="col-md-4">
                                <label for="retention_months" class="form-label">Local mail cache retention</label>
                                <div class="input-group">
                                    <input type="number" name="retention_months" id="retention_months" class="form-control" value="{{ $config['retention_months'] ?? 24 }}" min="1">
                                    <span class="input-group-text">months</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="size_limit_mb" class="form-label">Max stored message size</label>
                                <div class="input-group">
                                    <input type="number" name="size_limit_mb" id="size_limit_mb" class="form-control" value="{{ $config['size_limit_mb'] ?? 25 }}" min="1">
                                    <span class="input-group-text">MB</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label for="max_failures" class="form-label">Alert after failed polls</label>
                                <input type="number" name="max_failures" id="max_failures" class="form-control" value="{{ $config['max_failures'] ?? 3 }}" min="1">
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch mt-2">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        name="provider_deletion_reconciliation_enabled"
                                        id="provider_deletion_reconciliation_enabled"
                                        value="1"
                                        {{ $providerDeletionReconciliationEnabled ? 'checked' : '' }}>
                                    <label class="form-check-label" for="provider_deletion_reconciliation_enabled">
                                        Reconcile provider-side moves and deletions
                                    </label>
                                    <div class="form-text">
                                        Runs bounded, stable mailbox inventories and fails closed on incomplete evidence,
                                        UIDVALIDITY changes, provider errors, or ambiguous moves. Confirmed missing cache
                                        remains recoverable during the grace period before retention-protected cleanup.
                                    </div>
                                </div>
                                @if(!$providerDeletionReconciliationEnabled)
                                    <div class="alert alert-info py-2 small mt-2 mb-0" role="status">
                                        Provider deletion reconciliation is off. Enable it only after the pending Mail
                                        provider-deletion human review is complete.
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Retention eligibility preview -->
                        <hr class="my-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <h6 class="mb-1">Retention preview</h6>
                                <p class="text-muted small mb-0">
                                    Read-only preview for messages received before
                                    <strong>{{ $retentionPreview['cutoff_at']->format('Y-m-d H:i') }}</strong>.
                                    Provider-backed mail and protected evidence are never eligible here.
                                </p>
                            </div>
                            <span class="badge bg-light text-dark border">Monthly scheduled cleanup</span>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-6 col-xl-3">
                                <div class="border rounded px-2 py-2 h-100">
                                    <div class="text-muted small">Expired</div>
                                    <div class="fw-semibold">{{ $retentionPreview['expired_count'] }}</div>
                                </div>
                            </div>
                            <div class="col-6 col-xl-3">
                                <div class="border border-success rounded px-2 py-2 h-100">
                                    <div class="text-muted small">Eligible orphans</div>
                                    <div class="fw-semibold text-success">{{ $retentionPreview['eligible_count'] }}</div>
                                </div>
                            </div>
                            <div class="col-6 col-xl-3">
                                <div class="border border-warning rounded px-2 py-2 h-100">
                                    <div class="text-muted small">Protected</div>
                                    <div class="fw-semibold text-warning-emphasis">{{ $retentionPreview['protected_count'] }}</div>
                                </div>
                            </div>
                            <div class="col-6 col-xl-3">
                                <div class="border rounded px-2 py-2 h-100">
                                    <div class="text-muted small">Retention</div>
                                    <div class="fw-semibold">{{ $retentionPreview['retention_months'] }} months</div>
                                </div>
                            </div>
                        </div>
                        @if($retentionPreview['reason_breakdown'] !== [])
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">Protection reason</th>
                                            <th scope="col" class="text-end">Messages</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($retentionPreview['reason_breakdown'] as $reason)
                                            <tr>
                                                <td>{{ $reason['label'] }}</td>
                                                <td class="text-end">{{ $reason['count'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-muted small mb-0">No expired messages are currently protected by a retention blocker.</p>
                        @endif
                    </div>
                </div>

                <!-- 3. Attachment Import Policy -->
                <div class="card mb-4 shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Attachment Import Policy</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="attachment_max_count" class="form-label">Maximum attachments per message</label>
                                <input type="number" name="attachment_max_count" id="attachment_max_count" class="form-control"
                                       value="{{ old('attachment_max_count', $config['attachment_max_count'] ?? 20) }}" min="1" max="100">
                            </div>
                            <div class="col-md-6">
                                <label for="attachment_max_size_mb" class="form-label">Maximum size per attachment (MB)</label>
                                <input type="number" name="attachment_max_size_mb" id="attachment_max_size_mb" class="form-control"
                                       value="{{ old('attachment_max_size_mb', $config['attachment_max_size_mb'] ?? 10) }}" min="1" max="1024">
                            </div>
                            <div class="col-12">
                                <label for="attachment_allowed_mime_types" class="form-label">Allowed MIME types</label>
                                <textarea name="attachment_allowed_mime_types" id="attachment_allowed_mime_types" class="form-control font-monospace"
                                          rows="8">{{ old('attachment_allowed_mime_types', $config['attachment_allowed_mime_types'] ?? '') }}</textarea>
                                <div class="form-text">
                                    Enter one type per line or separate values with commas. Exact types and wildcards such as
                                    <code>image/*</code> are supported. Rejected files are skipped and logged without failing the email import.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Advanced Automation Trust -->
                <details class="card mb-4 shadow-sm border-0" @if($trustedTrustExpanded) open @endif>
                    <summary class="card-header bg-light d-flex justify-content-between align-items-center gap-3">
                        <span class="h5 mb-0">Advanced Automation Trust</span>
                        <span class="badge {{ $trustedTrustConfigured ? 'bg-success' : 'bg-secondary' }}">
                            {{ $trustedTrustConfigured ? 'Configured' : 'Off' }}
                        </span>
                    </summary>
                    <div class="card-body">
                        <p class="text-muted small">
                            Proxmox Mail Gateway, DNS, SPF, DKIM and DMARC remain the normal mail security
                            boundary. Configure these fields only when Nexum automation must trust gateway
                            authentication evidence before running sensitive rules.
                        </p>
                        <div class="alert alert-warning py-2 small" role="alert">
                            Only list infrastructure that strips untrusted inbound
                            <code>Authentication-Results</code> headers and adds its own result.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="trusted_authserv_ids" class="form-label">Trusted authserv IDs</label>
                                <textarea name="trusted_authserv_ids" id="trusted_authserv_ids"
                                          class="form-control font-monospace @error('trusted_authserv_ids') is-invalid @enderror"
                                          rows="5" placeholder="mx.example.no">{{ old('trusted_authserv_ids', $config['trusted_authserv_ids'] ?? '') }}</textarea>
                                @error('trusted_authserv_ids')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-text">One exact hostname per line. Configure the paired receiving-hop list.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="trusted_receiving_hops" class="form-label">Trusted receiving hops</label>
                                <textarea name="trusted_receiving_hops" id="trusted_receiving_hops"
                                          class="form-control font-monospace @error('trusted_receiving_hops') is-invalid @enderror"
                                          rows="5" placeholder="mail-gateway.example.no">{{ old('trusted_receiving_hops', $config['trusted_receiving_hops'] ?? '') }}</textarea>
                                @error('trusted_receiving_hops')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-text">
                                    Required whenever authserv IDs are configured. The first Received
                                    header must name one of these exact hosts after <code>by</code>.
                                </div>
                            </div>
                        </div>
                    </div>
                </details>

                <!-- 5. Identification & Threading (Read-only) -->
                <div class="card mb-4 bg-light text-muted border-0">
                    <div class="card-header">
                        <h5 class="mb-0">Identification & Threading (Policy)</h5>
                    </div>
                    <div class="card-body">
                        <p class="small mb-1">Precedence: <strong>Headers</strong> (Message-ID / In-Reply-To) over Subject token.</p>
                        <p class="small mb-0">Subject token format: <code>[#{number}]</code></p>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-info mb-4 shadow-sm">
                    <div class="card-header bg-info text-white">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <h5 class="mb-0">System Health</h5>
                            @php
                                $overallClass = match($health['overall'] ?? 'warning') {
                                    'ok' => 'bg-success',
                                    'error' => 'bg-danger',
                                    default => 'bg-warning text-dark',
                                };
                                $overallLabel = match($health['overall'] ?? 'warning') {
                                    'ok' => 'OK',
                                    'error' => 'Error',
                                    default => 'Warning',
                                };
                            @endphp
                            <span class="badge {{ $overallClass }}">{{ $overallLabel }}</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            @foreach($health['items'] ?? [] as $item)
                                @php
                                    $badgeClass = match($item['status']) {
                                        'ok' => 'bg-success',
                                        'error' => 'bg-danger',
                                        default => 'bg-warning text-dark',
                                    };
                                @endphp
                                <li class="list-group-item d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="fw-semibold">{{ $item['label'] }}</div>
                                        <div class="text-muted small">{{ $item['detail'] }}</div>
                                    </div>
                                    <span class="badge {{ $badgeClass }} rounded-pill text-nowrap">{{ $item['badge'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Mail AI</h5>
                    </div>
                    <div class="card-body">
                        <label for="mail_ai_default_agent_id" class="form-label">Default Email agent</label>
                        <select id="mail_ai_default_agent_id" name="mail_ai_default_agent_id" class="form-select @error('mail_ai_default_agent_id') is-invalid @enderror">
                            <option value="">Use global fallback agent</option>
                            @foreach($mailAiAgents as $agent)
                                <option value="{{ $agent->id }}" @selected((int) ($mailAiDomainAgent?->id ?? 0) === (int) $agent->id)>
                                    {{ $agent->name }} · {{ $agent->provider?->name ?? 'No provider' }} · {{ $agent->model ?: $agent->provider?->default_model }}
                                    @if($agent->is_default)
                                        · global fallback
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        @error('mail_ai_default_agent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @if($mailAiGlobalFallbackAgent)
                            <div class="mt-2 small text-muted">
                                Global fallback agent: <strong>{{ $mailAiGlobalFallbackAgent->name }}</strong>
                            </div>
                        @else
                            <div class="mt-2 small">
                                <span class="text-muted">No global fallback agent is configured.</span>
                            </div>
                        @endif
                        @if($mailAiDefaultAgent && ! ($mailAiRuntimeAvailability['available'] ?? false))
                            <div class="alert alert-warning py-2 px-3 mt-3 mb-0 small d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <span>
                                    Mail AI needs activation:
                                    <code>{{ $mailAiRuntimeAvailability['reason'] ?? 'not_ready' }}</code>
                                </span>
                                <a href="{{ route('tech.admin.system.integrations.ai.index') }}" class="btn btn-sm btn-outline-primary">
                                    AI Settings
                                </a>
                            </div>
                        @elseif($mailAiRuntimeAvailability['available'] ?? false)
                            <div class="mt-2 small text-success">
                                Mail AI ready for {{ $mailAiRuntimeAvailability['agent']?->name }} / {{ $mailAiRuntimeAvailability['model'] }}.
                            </div>
                        @endif
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">Shortcuts</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <a href="{{ route('tech.admin.settings.email.accounts') }}" class="list-group-item list-group-item-action">Open Accounts</a>
                            <a href="{{ route('tech.admin.settings.email.rules') }}" class="list-group-item list-group-item-action">Open Rules</a>
                            <a href="{{ route('tech.inbox.index') }}" class="list-group-item list-group-item-action">Open Fallback Inbox</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection
