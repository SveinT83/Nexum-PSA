<form method="POST" action="{{ route('tech.admin.nextcloud.connections.store') }}" class="js-nextcloud-connection-form">
    @csrf
    <input type="hidden" name="is_active" value="0">
    <input type="hidden" name="is_default" value="0">
    <input type="hidden" name="allow_user_credentials" value="0">
    <input type="hidden" name="calendar_sync_enabled" value="0">
    <input type="hidden" name="file_browser_enabled" value="0">
    <input type="hidden" name="users_groups_read_enabled" value="0">

    <div class="row g-3">
        <div class="col-md-5">
            <label for="name" class="form-label">Name</label>
            <input id="name" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label for="scope" class="form-label">Connection type</label>
            <select id="scope" name="scope" class="form-select js-nextcloud-scope @error('scope') is-invalid @enderror">
                @foreach(['global' => 'Internal/global', 'client' => 'Client', 'site' => 'Client site'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('scope', 'global') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('scope')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label for="mode" class="form-label">Mode</label>
            <select id="mode" name="mode" class="form-select js-nextcloud-mode @error('mode') is-invalid @enderror">
                @foreach(['read_only' => 'Read only', 'sync' => 'Sync', 'managed' => 'Managed'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('mode', 'read_only') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="form-text js-nextcloud-mode-help d-none">Client and site connections start as read-only.</div>
            @error('mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-md-6 js-nextcloud-client-field d-none">
            <label for="client_id" class="form-label">Client</label>
            <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror">
                <option value="">Choose client</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }}</option>
                @endforeach
            </select>
            @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6 js-nextcloud-site-field d-none">
            <label for="client_site_id" class="form-label">Site</label>
            <select id="client_site_id" name="client_site_id" class="form-select @error('client_site_id') is-invalid @enderror">
                <option value="">Choose site</option>
                @foreach($sites as $site)
                    <option value="{{ $site->id }}" @selected((string) old('client_site_id') === (string) $site->id)>{{ $site->client?->name }} · {{ $site->name }}</option>
                @endforeach
            </select>
            <div class="form-text">Used as the default import site for client Nextcloud users.</div>
            @error('client_site_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-md-8">
            <label for="base_url" class="form-label">Base URL</label>
            <input id="base_url" name="base_url" value="{{ old('base_url') }}" class="form-control @error('base_url') is-invalid @enderror" placeholder="https://cloud.example.com" required>
            <div class="form-text">Admin URL is generated automatically as <code>/settings/admin</code>.</div>
            @error('base_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label for="sync_interval_minutes" class="form-label">Sync interval</label>
            <input id="sync_interval_minutes" type="number" min="1" max="1440" name="sync_interval_minutes" value="{{ old('sync_interval_minutes', 15) }}" class="form-control @error('sync_interval_minutes') is-invalid @enderror">
            @error('sync_interval_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-md-6">
            <label for="service_username" class="form-label">Service username</label>
            <input id="service_username" name="service_username" value="{{ old('service_username') }}" class="form-control">
        </div>
        <div class="col-md-6">
            <label for="service_password" class="form-label">Service app password</label>
            <input id="service_password" type="password" name="service_password" class="form-control" autocomplete="new-password">
        </div>

        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" checked>
                <label class="form-check-label" for="is_active">Active</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_default" value="1" id="is_default" @checked(old('is_default'))>
                <label class="form-check-label" for="is_default">Default for this scope</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="allow_user_credentials" value="1" id="allow_user_credentials" @checked(old('allow_user_credentials'))>
                <label class="form-check-label" for="allow_user_credentials">Allow user fallback</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="calendar_sync_enabled" value="1" id="calendar_sync_enabled" @checked(old('calendar_sync_enabled'))>
                <label class="form-check-label" for="calendar_sync_enabled">Calendar sync</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="file_browser_enabled" value="1" id="file_browser_enabled" checked>
                <label class="form-check-label" for="file_browser_enabled">File browser</label>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="users_groups_read_enabled" value="1" id="users_groups_read_enabled" checked>
                <label class="form-check-label" for="users_groups_read_enabled">Users/groups</label>
            </div>
        </div>

        <div class="col-12 text-end">
            <button class="btn btn-primary" type="submit">Create Connection</button>
        </div>
    </div>
</form>
