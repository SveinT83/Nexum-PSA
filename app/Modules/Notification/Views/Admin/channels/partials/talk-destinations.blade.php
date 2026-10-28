{{-- Explicit paired targets replace arbitrary webhook URLs in ordinary setup. --}}
<div class="card mb-4">
    <div class="card-header py-2"><h2 class="h6 mb-0">Talk notification destinations</h2></div>
    <div class="card-body">
        <label for="talkGlobalDestination" class="form-label">Global default conversation for system notifications</label>
        <select name="config[talk_destination_id]" id="talkGlobalDestination" class="form-select">
            <option value="">Select an approved conversation</option>
            @foreach($talkConnections as $talkConnection)
                <optgroup label="{{ $talkConnection['name'] }}">
                    @foreach($talkConnection['destinations'] as $destination)
                        <option value="{{ $destination['id'] }}"
                            @selected((int) ($channel->config['talk_destination_id'] ?? 0) === $destination['id'])
                            @disabled(! $destination['eligible'] && (int) ($channel->config['talk_destination_id'] ?? 0) !== $destination['id'])>
                            {{ $destination['name'] }} — {{ $destination['conversation_token'] }}{{ $destination['eligible'] ? '' : ' (unavailable)' }}
                        </option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        @error('config.talk_destination_id')<div class="text-danger small">{{ $message }}</div>@enderror
        <p class="form-text">The selected conversation is bound to its own Nextcloud connection. Enable destinations in that connection's Talk Notifications panel first.</p>
        @foreach($talkConnections as $talkConnection)
            <div class="border-top py-2">
                <div class="d-flex justify-content-between gap-2">
                    <strong>{{ $talkConnection['name'] }}</strong>
                    <a href="{{ $talkConnection['settings_url'] }}#talk-notifications">Manage destinations</a>
                </div>
                <div class="small text-muted">Connection {{ $talkConnection['active'] ? 'active' : 'inactive' }};
                    Talk {{ $talkConnection['enabled'] ? 'enabled' : 'disabled' }}</div>
                <ul class="small mb-0">
                    @forelse($talkConnection['destinations'] as $destination)
                        <li>{{ $destination['name'] }} — <code>{{ $destination['conversation_token'] }}</code>:
                            {{ $destination['enabled'] ? 'enabled' : 'disabled' }}, {{ $destination['health'] }}</li>
                    @empty
                        <li>No discovered conversations.</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
        @if(empty($talkConnections))
            <p class="text-muted mb-0">Configure an active Nextcloud connection, then refresh its Talk conversations.</p>
        @endif
        @if(($channel->config['delivery_mode'] ?? null) !== 'conversation' && filled($channel->config['default_webhook_url'] ?? null))
            <p class="small text-muted mt-2">Legacy delivery is retained for migration. Select a registered conversation to use ordinary service-user delivery.</p>
        @endif
    </div>
</div>
