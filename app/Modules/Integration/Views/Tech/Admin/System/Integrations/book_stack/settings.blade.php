@extends('layouts.default_tech')

@section('title', 'BookStack Settings')

@section('pageHeader')
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
            <li class="breadcrumb-item active" aria-current="page">BookStack Settings</li>
        </ol>
    </nav>
    <h1>BookStack Settings</h1>
@endsection

@section('content')
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <form action="{{ route('tech.admin.system.integrations.book_stack.update') }}" method="POST">
                    @csrf
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">API Configuration</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="server" class="form-label">BookStack URL</label>
                                <input type="url" class="form-control" id="server" name="server"
                                       value="{{ old('server', $integration->server ?? '') }}"
                                       placeholder="https://docs.example.com" required>
                                <small class="text-muted">Enter the root URL for the BookStack instance, without the /api path.</small>
                            </div>

                            <div class="mb-3">
                                <label for="token_id" class="form-label">Token ID</label>
                                <input type="password" class="form-control" id="token_id" name="token_id" autocomplete="new-password"
                                       placeholder="{{ $integration && $integration->getSecret('token_id') ? '********' : 'Enter Token ID' }}">
                                <small class="text-muted">Create this in BookStack from a user with Access System API permission.</small>
                            </div>

                            <div class="mb-3">
                                <label for="token_secret" class="form-label">Token Secret</label>
                                <input type="password" class="form-control" id="token_secret" name="token_secret" autocomplete="new-password"
                                       placeholder="{{ $integration && $integration->getSecret('token_secret') ? '********' : 'Enter Token Secret' }}">
                                <small class="text-muted">Stored encrypted. Leave blank to keep the current secret.</small>
                            </div>

                            <div class="mb-3">
                                <label for="sync_interval_minutes" class="form-label">Sync Interval</label>
                                <input type="number" class="form-control" id="sync_interval_minutes" name="sync_interval_minutes"
                                       value="{{ old('sync_interval_minutes', $integration->config['sync_interval_minutes'] ?? 60) }}"
                                       min="1" max="1440">
                                <small class="text-muted">Minutes between scheduled pulls. Default is 60.</small>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input type="hidden" name="two_way_sync_enabled" value="0">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    role="switch"
                                    id="two_way_sync_enabled"
                                    name="two_way_sync_enabled"
                                    value="1"
                                    {{ old('two_way_sync_enabled', $integration->config['two_way_sync_enabled'] ?? false) ? 'checked' : '' }}>
                                <label class="form-check-label" for="two_way_sync_enabled">
                                    Enable two-way sync
                                </label>
                                <div class="form-text">
                                    Allows local Knowledge shelves, books, chapters, and pages to be marked for future push back to BookStack.
                                </div>
                            </div>

                            <div class="alert alert-info mb-0">
                                Two-way sync is a configuration flag for the upcoming push workflow. Current manual sync still pulls BookStack content into Knowledge.
                            </div>

                            <div class="mt-4 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Save Configuration</button>
                            </div>
                        </div>
                    </div>
                </form>

                <form action="{{ route('tech.admin.system.integrations.book_stack.test') }}" method="POST">
                    @csrf
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Connection Test</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">
                                Test the BookStack API connection after the URL, token ID, and token secret are available.
                            </p>
                            <button type="submit" class="btn btn-outline-primary"
                                    {{ (!$integration || !$integration->server || !$integration->getSecret('token_id') || !$integration->getSecret('token_secret')) ? 'disabled' : '' }}>
                                Test Connection
                            </button>
                            @if(!$integration || !$integration->server || !$integration->getSecret('token_id') || !$integration->getSecret('token_secret'))
                                <p class="text-muted small mt-2 mb-0">Save the BookStack URL and API credentials before testing.</p>
                            @endif
                        </div>
                    </div>
                </form>

                <div class="card mb-4">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center gap-3">
                            <h5 class="mb-0">Synchronization</h5>
                            @if($integration && $integration->last_sync_at)
                                <span class="badge bg-light text-dark border">
                                    Last sync {{ $integration->last_sync_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="card-body">
                        @php
                            $syncSummary = $integration->config['last_sync_summary'] ?? null;
                            $pushSummary = $integration->config['last_push_summary'] ?? null;
                            $twoWayEnabled = $integration->config['two_way_sync_enabled'] ?? false;
                            $canSync = $integration
                                && $integration->server
                                && $integration->getSecret('token_id')
                                && $integration->getSecret('token_secret');
                        @endphp

                        {{-- Manual sync currently pulls BookStack content into Knowledge; the two-way setting prepares the later push workflow. --}}
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">
                                    Pull visible BookStack pages into Knowledge as synchronized internal articles. Scheduled pulls run automatically when due.
                                </p>
                                <p class="small text-muted mb-0">
                                    Existing synced articles are skipped when the source checksum has not changed.
                                    Sync mode: {{ ($integration->config['two_way_sync_enabled'] ?? false) ? 'Two-way planned' : 'Pull only' }}.
                                </p>
                            </div>

                            <form action="{{ route('tech.admin.system.integrations.book_stack.sync') }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-primary" {{ $canSync ? '' : 'disabled' }}>
                                    <i class="bi bi-arrow-repeat"></i> Sync Now
                                </button>
                            </form>
                        </div>

                        <hr>

                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <p class="text-muted mb-1">
                                    Push local Knowledge shelves, books, chapters, and pages into BookStack.
                                </p>
                                <p class="small text-muted mb-0">
                                    Local records become BookStack-backed after a successful push.
                                </p>
                            </div>

                            <form action="{{ route('tech.admin.system.integrations.book_stack.push') }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary" {{ ($canSync && $twoWayEnabled) ? '' : 'disabled' }}>
                                    <i class="bi bi-cloud-upload"></i> Push Local Changes
                                </button>
                            </form>
                        </div>

                        @if(!$canSync)
                            <p class="text-muted small mt-3 mb-0">Save and test BookStack credentials before running sync.</p>
                        @elseif(!$twoWayEnabled)
                            <p class="text-muted small mt-3 mb-0">Enable two-way sync before pushing local content to BookStack.</p>
                        @endif

                        @if($syncSummary)
                            <div class="row g-2 mt-3">
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">Created</div>
                                        <div class="fw-semibold">{{ $syncSummary['created'] ?? 0 }}</div>
                                    </div>
                                </div>
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">Updated</div>
                                        <div class="fw-semibold">{{ $syncSummary['updated'] ?? 0 }}</div>
                                    </div>
                                </div>
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">Skipped</div>
                                        <div class="fw-semibold">{{ $syncSummary['skipped'] ?? 0 }}</div>
                                    </div>
                                </div>
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">Failed</div>
                                        <div class="fw-semibold">{{ $syncSummary['failed'] ?? 0 }}</div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if($pushSummary)
                            <div class="row g-2 mt-3">
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">Pushed shelves</div>
                                        <div class="fw-semibold">{{ $pushSummary['shelves'] ?? 0 }}</div>
                                    </div>
                                </div>
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">Pushed books</div>
                                        <div class="fw-semibold">{{ $pushSummary['books'] ?? 0 }}</div>
                                    </div>
                                </div>
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">Pushed chapters</div>
                                        <div class="fw-semibold">{{ $pushSummary['chapters'] ?? 0 }}</div>
                                    </div>
                                </div>
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">Pushed pages</div>
                                        <div class="fw-semibold">{{ $pushSummary['pages'] ?? 0 }}</div>
                                    </div>
                                </div>
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-2">
                                        <div class="small text-muted">Push failed</div>
                                        <div class="fw-semibold">{{ $pushSummary['failed'] ?? 0 }}</div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Status Overview</h5>
        </div>
        <div class="card-body">
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Integration Status
                    <span class="badge bg-{{ ($integration && $integration->status === 'active') ? 'success' : 'secondary' }}">
                        {{ $integration ? ucfirst($integration->status) : 'Not Initialized' }}
                    </span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Connection Health
                    @if(!$integration || !$integration->server || !$integration->getSecret('token_id') || !$integration->getSecret('token_secret'))
                        <span class="badge bg-secondary">Not configured</span>
                    @elseif($integration->is_healthy === null || (!$integration->is_healthy && !$integration->last_error))
                        <span class="badge bg-warning">Pending</span>
                    @elseif($integration->is_healthy)
                        <span class="badge bg-success">Healthy</span>
                    @else
                        <span class="badge bg-danger">Error</span>
                    @endif
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Last Sync
                    <span class="text-muted">{{ ($integration && $integration->last_sync_at) ? $integration->last_sync_at->diffForHumans() : 'Never' }}</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    Sync Mode
                    <span class="badge bg-{{ ($integration->config['two_way_sync_enabled'] ?? false) ? 'primary' : 'secondary' }}">
                        {{ ($integration->config['two_way_sync_enabled'] ?? false) ? 'Two-way' : 'Pull only' }}
                    </span>
                </li>
            </ul>
        </div>
    </div>

    @if($integration && $integration->last_error)
        <div class="alert alert-danger">
            <h6>Last Error:</h6>
            <p class="small mb-0">{{ $integration->last_error }}</p>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Documentation</h5>
            <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" data-bs-toggle="modal" data-bs-target="#bookStackDocModal">
                <i class="bi bi-info-circle"></i>
            </button>
        </div>
        <div class="card-body text-center">
            <p>Provider notes for the BookStack knowledge integration.</p>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#bookStackDocModal">
                <i class="bi bi-info-circle"></i> View Documentation
            </button>
        </div>
    </div>

    <div class="modal fade" id="bookStackDocModal" tabindex="-1" aria-labelledby="bookStackDocModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bookStackDocModalLabel">BookStack Integration Guide</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="markdown-body">
                        @php
                            $docPath = app_path('Modules/Integration/Views/Tech/Admin/System/Integrations/book_stack/chatgpt.md');
                            if (file_exists($docPath)) {
                                if (class_exists('\Parsedown')) {
                                    $parsedown = new \Parsedown();
                                    echo $parsedown->text(file_get_contents($docPath));
                                } else {
                                    echo '<div class="alert alert-warning small">Markdown parser not found. Raw doc:</div>';
                                    echo '<pre style="white-space: pre-wrap; font-size: 0.85rem;">' . e(file_get_contents($docPath)) . '</pre>';
                                }
                            } else {
                                echo "Documentation file not found.";
                            }
                        @endphp
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection
