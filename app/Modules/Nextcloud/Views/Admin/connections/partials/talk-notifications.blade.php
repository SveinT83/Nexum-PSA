{{-- Ordinary service-user Talk notifications: independent destinations and explicit actions. --}}
@php
    $talkRegistry = app(\App\Modules\Nextcloud\Services\TalkDestinationRegistry::class);
    $talk = $talkRegistry->context($connection);
    $talkGlobal = \App\Modules\Notification\Models\NotificationChannel::getByDriver('nextcloud_talk');
@endphp
<section class="card mb-3" id="talk-notifications">
    <div class="card-header py-2"><h2 class="h6 mb-0">Talk Notifications</h2></div>
    <div class="card-body">
        <p class="small">Send as the configured Nextcloud service user. Choose conversations for this connection;
            <a href="{{ route('tech.admin.notification-channels.index') }}">Notification Channels</a> controls the global switch and default.</p>
        @if(! $talk['schema_ready'])
            <div class="alert alert-warning mb-0">A database update is required before Talk notifications can be configured.</div>
        @else
            @unless($talk['write_capable'] && $talk['active'])
                <div class="alert alert-warning">Delivery requires an active connection that permits writes. Other Nextcloud features keep their own settings.</div>
            @endunless
            <form method="POST" action="{{ route('tech.admin.nextcloud.talk-notifications.refresh', $connection) }}" class="mb-3">
                @csrf
                <input type="hidden" name="revision" value="{{ $talk['revision'] }}">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Refresh conversations</button>
                <span class="small text-muted ms-2">Last successful refresh: {{ $talk['last_refreshed_at'] ?? 'Never' }}</span>
            </form>
            <form method="POST" action="{{ route('tech.admin.nextcloud.talk-notifications.update', $connection) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="revision" value="{{ $talk['revision'] }}">
                <div class="form-check form-switch mb-3">
                    <input type="checkbox" name="enabled" value="1" id="talkEnabled" class="form-check-input" @checked($talk['enabled'])>
                    <label for="talkEnabled" class="form-check-label">Enable Talk notifications for this connection</label>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Available destination</th><th>Conversation / token</th><th>Health</th><th>Last test</th><th></th></tr></thead>
                        <tbody>
                        @forelse($talk['destinations'] as $destination)
                            <tr>
                                <td><input type="checkbox" class="form-check-input" name="destination_ids[]" value="{{ $destination['id'] }}"
                                    aria-label="Enable {{ $destination['name'] }}" @checked($destination['enabled'])></td>
                                <td>{{ $destination['name'] }}<br><code>{{ $destination['conversation_token'] }}</code></td>
                                <td>{{ $destination['health'] }} @unless($destination['available'])<span class="small text-muted">(not currently accessible)</span>@endunless</td>
                                <td class="small">{{ $destination['last_test_state'] ?? 'Not tested' }}<br>{{ $destination['last_tested_at'] ?? '' }}
                                    @if($destination['last_test_reason'])<br>{{ $destination['last_test_reason'] }}@endif</td>
                                <td><button type="submit" class="btn btn-sm btn-outline-secondary" form="talkTest{{ $destination['id'] }}"
                                    @disabled(! $talkGlobal?->is_enabled || ! $talk['enabled'] || ! $destination['enabled'] || ! $destination['available'] || $destination['health'] !== 'available')>Send test</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">Refresh conversations accessible to the configured service user.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-md-8">
                        <label for="talkDefault" class="form-label">Default conversation for this connection</label>
                        <select name="default_destination_id" id="talkDefault" class="form-select">
                            <option value="">Select default</option>
                            @foreach($talk['destinations'] as $destination)
                                <option value="{{ $destination['id'] }}" @selected($talk['default_destination_id'] === $destination['id'])>{{ $destination['name'] }} — {{ $destination['conversation_token'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 text-end"><button class="btn btn-sm btn-primary" type="submit">Save Talk settings</button></div>
                </div>
                <p class="form-text">Select available destinations and one default before enabling. A missing or disabled target never uses another connection.
                    Tests require the global channel and this destination enabled. A message read-back confirms storage, not human receipt.</p>
            </form>
            {{-- Separate explicit test forms keep target and action identity fixed without nested forms. --}}
            @foreach($talk['destinations'] as $destination)
                <form method="POST" id="talkTest{{ $destination['id'] }}" class="d-none"
                    action="{{ route('tech.admin.nextcloud.talk-notifications.test', [$connection, $destination['id']]) }}">
                    @csrf
                    <input type="hidden" name="revision" value="{{ $talk['revision'] }}">
                    <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="confirm" value="1">
                </form>
            @endforeach
            @if($connection->canManageRemote())
                <details class="mt-3">
                    <summary>Create a private Talk conversation</summary>
                    <form method="POST" class="mt-2" action="{{ route('tech.admin.nextcloud.talk-notifications.create', $connection) }}">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $talk['revision'] }}">
                        <input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="confirm" value="1">
                        <label for="talkRoomName" class="form-label">Conversation name</label>
                        <div class="input-group input-group-sm">
                            <input name="name" id="talkRoomName" maxlength="255" required class="form-control">
                            <button type="submit" class="btn btn-outline-primary">Create private conversation</button>
                        </div>
                        <div class="form-text">Creates a private group as the service user. Select and enable it separately; no participants are added automatically.</div>
                    </form>
                </details>
            @endif
        @endif
    </div>
</section>
