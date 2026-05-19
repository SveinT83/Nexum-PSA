<form method="POST" action="{{ route('tech.admin.nextcloud.connections.update', $connection) }}" class="p-2 js-nextcloud-connection-form">
    @csrf
    @method('PATCH')
    <input type="hidden" name="is_active" value="0">
    <input type="hidden" name="is_default" value="0">
    <input type="hidden" name="allow_user_credentials" value="0">
    <input type="hidden" name="calendar_sync_enabled" value="0">
    <input type="hidden" name="file_browser_enabled" value="0">
    <input type="hidden" name="users_groups_read_enabled" value="0">
    <input type="hidden" name="root_folder" value="{{ $connection->root_folder }}">
    <input type="hidden" name="documents_folder" value="{{ $connection->documents_folder }}">

    <div class="row g-2">
        <div class="col-md-3">
            <label for="edit_name_{{ $connection->id }}" class="form-label small">Name</label>
            <input id="edit_name_{{ $connection->id }}" name="name" value="{{ old('name', $connection->name) }}" class="form-control form-control-sm" required>
        </div>
        <div class="col-md-2">
            <label for="edit_scope_{{ $connection->id }}" class="form-label small">Connection type</label>
            <select id="edit_scope_{{ $connection->id }}" name="scope" class="form-select form-select-sm js-nextcloud-scope">
                @foreach(['global' => 'Internal/global', 'client' => 'Client', 'site' => 'Client site'] as $value => $label)
                    <option value="{{ $value }}" @selected($connection->scope === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label for="edit_mode_{{ $connection->id }}" class="form-label small">Mode</label>
            <select id="edit_mode_{{ $connection->id }}" name="mode" class="form-select form-select-sm js-nextcloud-mode">
                @foreach(['read_only' => 'Read only', 'sync' => 'Sync', 'managed' => 'Managed'] as $value => $label)
                    <option value="{{ $value }}" @selected($connection->mode === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="form-text js-nextcloud-mode-help d-none">Client and site connections start as read-only.</div>
        </div>
        <div class="col-md-3 js-nextcloud-client-field">
            <label for="edit_client_id_{{ $connection->id }}" class="form-label small">Client</label>
            <select id="edit_client_id_{{ $connection->id }}" name="client_id" class="form-select form-select-sm">
                <option value="">No client</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}" @selected($connection->client_id === $client->id)>{{ $client->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2 js-nextcloud-site-field">
            <label for="edit_client_site_id_{{ $connection->id }}" class="form-label small">Default import site</label>
            <select id="edit_client_site_id_{{ $connection->id }}" name="client_site_id" class="form-select form-select-sm">
                <option value="">No site</option>
                @foreach($sites as $site)
                    <option value="{{ $site->id }}" @selected($connection->client_site_id === $site->id)>{{ $site->client?->name }} · {{ $site->name }}</option>
                @endforeach
            </select>
            <div class="form-text">Used for imported client contacts.</div>
        </div>

        <div class="col-md-4">
            <label for="edit_base_url_{{ $connection->id }}" class="form-label small">Base URL</label>
            <input id="edit_base_url_{{ $connection->id }}" name="base_url" value="{{ old('base_url', $connection->base_url) }}" class="form-control form-control-sm" required>
        </div>
        <div class="col-md-4">
            <label for="edit_admin_url_{{ $connection->id }}" class="form-label small">Admin URL override</label>
            <input id="edit_admin_url_{{ $connection->id }}" name="admin_url" value="{{ old('admin_url', $connection->admin_url) }}" class="form-control form-control-sm">
            <div class="form-text">Leave blank to use Base URL + <code>/settings/admin</code>.</div>
        </div>
        <div class="col-md-2">
            <label for="edit_sync_interval_minutes_{{ $connection->id }}" class="form-label small">Sync interval</label>
            <input id="edit_sync_interval_minutes_{{ $connection->id }}" type="number" min="1" max="1440" name="sync_interval_minutes" value="{{ old('sync_interval_minutes', $connection->sync_interval_minutes) }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_default" value="1" id="edit_is_default_{{ $connection->id }}" @checked($connection->is_default)>
                <label class="form-check-label small" for="edit_is_default_{{ $connection->id }}">Default</label>
            </div>
        </div>

        <div class="col-md-3">
            <label for="edit_service_username_{{ $connection->id }}" class="form-label small">Service username</label>
            <input id="edit_service_username_{{ $connection->id }}" name="service_username" value="{{ old('service_username', $connection->service_username) }}" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
            <label for="edit_service_password_{{ $connection->id }}" class="form-label small">New service app password</label>
            <input id="edit_service_password_{{ $connection->id }}" type="password" name="service_password" class="form-control form-control-sm" autocomplete="new-password" placeholder="Leave blank to keep current">
        </div>

        <div class="col-md-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="edit_is_active_{{ $connection->id }}" @checked($connection->is_active)>
                <label class="form-check-label small" for="edit_is_active_{{ $connection->id }}">Active</label>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="allow_user_credentials" value="1" id="edit_allow_user_credentials_{{ $connection->id }}" @checked($connection->allow_user_credentials)>
                <label class="form-check-label small" for="edit_allow_user_credentials_{{ $connection->id }}">Allow user credentials fallback</label>
            </div>
        </div>
        <div class="col-md-2">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="calendar_sync_enabled" value="1" id="edit_calendar_sync_enabled_{{ $connection->id }}" @checked((bool) ($connection->settings['calendar_sync_enabled'] ?? false))>
                <label class="form-check-label small" for="edit_calendar_sync_enabled_{{ $connection->id }}">Calendar sync</label>
            </div>
        </div>
        <div class="col-md-2">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="file_browser_enabled" value="1" id="edit_file_browser_enabled_{{ $connection->id }}" @checked((bool) ($connection->settings['file_browser_enabled'] ?? true))>
                <label class="form-check-label small" for="edit_file_browser_enabled_{{ $connection->id }}">File browser</label>
            </div>
        </div>
        <div class="col-md-2">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="users_groups_read_enabled" value="1" id="edit_users_groups_read_enabled_{{ $connection->id }}" @checked((bool) ($connection->settings['users_groups_read_enabled'] ?? true))>
                <label class="form-check-label small" for="edit_users_groups_read_enabled_{{ $connection->id }}">Users/groups</label>
            </div>
        </div>

        <div class="col-12 text-end">
            <button class="btn btn-sm btn-primary" type="submit">Save Connection</button>
        </div>
    </div>
</form>
