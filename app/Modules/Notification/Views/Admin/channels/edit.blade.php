@extends('layouts.default_tech')

@section('title', 'Configure {{ $channel->label }}')

@section('pageHeader')
    <h1><i class="bi bi-gear me-2"></i>{{ $channel->label }} Settings</h1>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('warning'))
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                {{ session('warning') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <form action="{{ route('tech.admin.notification-channels.update', $channel) }}" method="POST">
            @csrf
            @method('PUT')

            {{-- Enable/disable --}}
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0">General</h5>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="is_enabled" value="1"
                               class="form-check-input" id="isEnabled"
                               {{ $channel->is_enabled ? 'checked' : '' }}>
                        <label class="form-check-label fw-bold" for="isEnabled">
                            Enable {{ $channel->label }}
                        </label>
                        <p class="text-muted small mt-1">
                            When enabled, users can choose to receive notifications through this channel.
                        </p>
                    </div>

                    @if($channel->last_tested_at)
                        <div class="small text-muted">
                            Last tested: {{ $channel->last_tested_at->toDateTimeString() }}
                            — {{ $channel->last_test_result }}
                        </div>
                    @endif
                </div>
            </div>

            @if($channel->driver === 'nextcloud_talk')
                {{-- Nextcloud Talk specific config --}}
                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-chat-dots me-2"></i>Nextcloud Talk Configuration</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="baseUrl" class="form-label">Nextcloud Base URL</label>
                            <input type="url" name="config[base_url]" id="baseUrl"
                                   class="form-control"
                                   value="{{ $channel->config['base_url'] ?? '' }}"
                                   placeholder="https://cloud.example.com">
                            <div class="form-text">The base URL of your Nextcloud instance (used for building links in messages).</div>
                        </div>

                        <div class="mb-3">
                            <label for="defaultWebhookUrl" class="form-label">Default Webhook URL</label>
                            <input type="url" name="config[default_webhook_url]" id="defaultWebhookUrl"
                                   class="form-control"
                                   value="{{ $channel->config['default_webhook_url'] ?? '' }}"
                                   placeholder="https://cloud.example.com/apps/webhook/...">
                            <div class="form-text">
                                The default Nextcloud Talk webhook URL. Users can override this with their own per-user webhook URL.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="apiToken" class="form-label">API Token <small class="text-muted">(optional, for future use)</small></label>
                            <input type="password" name="secrets[api_token]" id="apiToken"
                                   class="form-control"
                                   placeholder="{{ $channel->getSecret('api_token') ? '••••••••' : 'Enter token' }}"
                                   autocomplete="new-password">
                            <div class="form-text">Leave blank to keep the existing token. Used for direct API calls (if needed).</div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Setup Instructions</h5>
                    </div>
                    <div class="card-body">
                        <ol>
                            <li>In Nextcloud Talk, go to <strong>Settings → Webhooks</strong> (or ask your Nextcloud admin).</li>
                            <li>Create a new webhook for the conversation where you want notifications.</li>
                            <li>Copy the webhook URL and paste it in the <strong>Default Webhook URL</strong> field above.</li>
                            <li>Enable the channel and click <strong>Test Connection</strong>.</li>
                            <li>Users can also set their own webhook URL in their <strong>Notification Preferences</strong> to receive personal notifications.</li>
                        </ol>
                    </div>
                </div>
            @endif

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Save Configuration
                </button>
                <a href="{{ route('tech.admin.notification-channels.test', $channel) }}"
                   class="btn btn-outline-secondary"
                   onclick="return confirm('Send a test notification?')">
                    <i class="bi bi-lightning me-1"></i> Test Connection
                </a>
                <a href="{{ route('tech.admin.notification-channels.index') }}"
                   class="btn btn-link">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" />
    @endif
@endsection