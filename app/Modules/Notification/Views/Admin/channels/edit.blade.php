@extends('layouts.default_tech')

@section('title', "Configure {$channel->label}")

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-0">{{ $channel->label }} Settings</h1>
    </div>
    <div class="col-auto">
        <x-buttons.back url="{{ route('tech.admin.notification-channels.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
<div class="row">
    <div class="col-lg-9 col-xl-8">
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
                <div class="card-header py-2">
                    <h5 class="mb-0">General</h5>
                </div>
                <div class="card-body">
                    @if($channel->driver === 'nextcloud_talk' && ! $nextcloudReady)
                        <div class="alert alert-warning mb-3">
                            Nextcloud Talk notifications need an active Nextcloud integration before this channel can be enabled.
                            @if(Route::has('tech.admin.nextcloud.connections.index'))
                                <a href="{{ route('tech.admin.nextcloud.connections.index') }}">Open Nextcloud settings</a>.
                            @endif
                        </div>
                    @endif

                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="is_enabled" value="1"
                               class="form-check-input" id="isEnabled"
                               {{ $channel->is_enabled && ($channel->driver !== 'nextcloud_talk' || $nextcloudReady) ? 'checked' : '' }}
                               {{ $channel->driver === 'nextcloud_talk' && ! $nextcloudReady ? 'disabled' : '' }}>
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
                    <div class="card-header py-2">
                        <h5 class="mb-0">Nextcloud Talk Configuration</h5>
                    </div>
                    <div class="card-body">
                        @if($nextcloudConnection)
                            <div class="border rounded p-3 mb-3 bg-light">
                                <div class="small text-muted">Using Nextcloud integration</div>
                                <div class="fw-semibold">{{ $nextcloudConnection->name }}</div>
                                <div class="small text-muted text-break">{{ $nextcloudConnection->base_url }}</div>
                                @if(Route::has('tech.admin.nextcloud.connections.show'))
                                    <a class="small" href="{{ route('tech.admin.nextcloud.connections.show', $nextcloudConnection) }}">Open integration settings</a>
                                @endif
                            </div>
                        @endif

                        <div class="mb-3">
                            <label for="defaultWebhookUrl" class="form-label">Default Webhook URL</label>
                            <input type="url" name="config[default_webhook_url]" id="defaultWebhookUrl"
                                   class="form-control @error('config.default_webhook_url') is-invalid @enderror"
                                   value="{{ $channel->config['default_webhook_url'] ?? '' }}"
                                   placeholder="https://cloud.example.com/apps/webhook/...">
                            @error('config.default_webhook_url')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                Default delivery target for system notifications. Users can still use their own per-user webhook URL in notification preferences.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header py-2">
                        <h5 class="mb-0">Setup Instructions</h5>
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
                <button type="submit" form="notification-channel-test-form" class="btn btn-outline-secondary" onclick="return confirm('Send a test notification?')">
                    <i class="bi bi-lightning me-1"></i> Test Connection
                </button>
                <a href="{{ route('tech.admin.notification-channels.index') }}"
                   class="btn btn-link">
                    Cancel
                </a>
            </div>
        </form>

        <form id="notification-channel-test-form" method="POST" action="{{ route('tech.admin.notification-channels.test', $channel) }}" class="d-none">
            @csrf
        </form>
    </div>
</div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="system" />
@endsection
