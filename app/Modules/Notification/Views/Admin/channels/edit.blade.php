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
    <div class="col-12">
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
                            @if($channel->driver === 'sms')
                                When enabled, approved workflows can log transactional SMS through the configured provider.
                            @else
                                When enabled, users can choose to receive notifications through this channel.
                            @endif
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
                            <div class="row g-3 mb-3">
                                <div class="col-lg-6">
                                    <label for="nextcloudConnectionId" class="form-label">Nextcloud Integration</label>
                                    <select name="config[nextcloud_connection_id]" id="nextcloudConnectionId" class="form-select @error('config.nextcloud_connection_id') is-invalid @enderror">
                                        @foreach($nextcloudConnections as $connection)
                                            <option value="{{ $connection->id }}"
                                                    data-name="{{ $connection->name }}"
                                                    data-base-url="{{ $connection->base_url }}"
                                                    data-settings-url="{{ Route::has('tech.admin.nextcloud.connections.show') ? route('tech.admin.nextcloud.connections.show', $connection) : '' }}"
                                                    {{ $nextcloudConnection->id === $connection->id ? 'selected' : '' }}>
                                                {{ $connection->name }}
                                                @if($connection->scope === 'global' && $connection->is_default)
                                                    (global default)
                                                @elseif($connection->is_default)
                                                    (default)
                                                @endif
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('config.nextcloud_connection_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                    <div class="form-text">Defaults to the active global default Nextcloud integration.</div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="border rounded p-3 bg-light h-100">
                                        <div class="small text-muted">Selected integration</div>
                                        <div class="fw-semibold" id="selectedNextcloudName">{{ $nextcloudConnection->name }}</div>
                                        <div class="small text-muted text-break" id="selectedNextcloudBaseUrl">{{ $nextcloudConnection->base_url }}</div>
                                        @if(Route::has('tech.admin.nextcloud.connections.show'))
                                            <a class="small" id="selectedNextcloudSettingsUrl" href="{{ route('tech.admin.nextcloud.connections.show', $nextcloudConnection) }}">Open integration settings</a>
                                        @endif
                                    </div>
                                </div>
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

            @if($channel->driver === 'sms')
                <div class="card shadow-sm mb-4">
                    <div class="card-header py-2">
                        <h5 class="mb-0">SMS Configuration</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="smsProvider" class="form-label">Provider</label>
                                <select name="config[provider]" id="smsProvider" class="form-select @error('config.provider') is-invalid @enderror">
                                    <option value="dry_run" @selected(($channel->config['provider'] ?? 'dry_run') === 'dry_run')>Dry-run</option>
                                </select>
                                @error('config.provider')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-text">Dry-run logs messages without sending to an external provider.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="smsSenderName" class="form-label">Sender name</label>
                                <input type="text" name="config[sender_name]" id="smsSenderName" class="form-control @error('config.sender_name') is-invalid @enderror" value="{{ old('config.sender_name', $channel->config['sender_name'] ?? 'Nexum') }}" maxlength="40">
                                @error('config.sender_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <label for="smsDefaultCountryCode" class="form-label">Default country code</label>
                                <input type="text" name="config[default_country_code]" id="smsDefaultCountryCode" class="form-control @error('config.default_country_code') is-invalid @enderror" value="{{ old('config.default_country_code', $channel->config['default_country_code'] ?? '+47') }}" placeholder="+47">
                                @error('config.default_country_code')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                                <div class="form-text">Used when a stored phone number is local format.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header py-2">
                        <h5 class="mb-0">Dry-Run Test</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-7">
                                <label for="smsTestContactId" class="form-label">Contact</label>
                                <select name="test_contact_id" id="smsTestContactId" form="notification-channel-test-form" class="form-select">
                                    <option value="">Select contact with phone</option>
                                    @foreach($smsTestContacts as $contact)
                                        @php($phone = $contact->phones->firstWhere('is_primary', true) ?: $contact->phones->first())
                                        <option value="{{ $contact->id }}">
                                            {{ $contact->display_name }} @if($phone) - {{ $phone->phone }}{{ $phone->sms_allowed ? ' (SMS allowed)' : ' (SMS not allowed)' }} @endif
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">The send action still blocks contacts without SMS permission.</div>
                            </div>
                            <div class="col-lg-5">
                                <label for="smsTestTemplateKey" class="form-label">Template</label>
                                <select name="test_template_key" id="smsTestTemplateKey" form="notification-channel-test-form" class="form-select">
                                    @foreach($smsTemplates as $template)
                                        <option value="{{ $template->key }}">{{ $template->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="d-flex justify-content-between gap-2">
                <button type="submit" form="notification-channel-test-form" class="btn btn-outline-secondary" onclick="return confirm('Send a test notification?')">
                    <i class="bi bi-lightning me-1"></i> Test Connection
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> Save Configuration
                </button>
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

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const connectionSelect = document.getElementById('nextcloudConnectionId');
            const selectedName = document.getElementById('selectedNextcloudName');
            const selectedBaseUrl = document.getElementById('selectedNextcloudBaseUrl');
            const selectedSettingsUrl = document.getElementById('selectedNextcloudSettingsUrl');

            if (!connectionSelect || !selectedName || !selectedBaseUrl) {
                return;
            }

            connectionSelect.addEventListener('change', function () {
                const option = connectionSelect.selectedOptions[0];

                selectedName.textContent = option?.dataset.name || '';
                selectedBaseUrl.textContent = option?.dataset.baseUrl || '';

                if (selectedSettingsUrl) {
                    selectedSettingsUrl.href = option?.dataset.settingsUrl || '#';
                }
            });
        });
    </script>
@endsection
