<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shared connection validation and client/site ownership. */
class ValidateNextcloudConnection
{
    public function handle(Request $request, ?NextcloudConnection $connection = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::in([NextcloudConnection::SCOPE_GLOBAL, NextcloudConnection::SCOPE_CLIENT, NextcloudConnection::SCOPE_SITE])],
            'mode' => ['required', Rule::in([NextcloudConnection::MODE_READ_ONLY, NextcloudConnection::MODE_SYNC, NextcloudConnection::MODE_MANAGED])],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'client_site_id' => ['nullable', 'integer', 'exists:client_sites,id'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'base_url' => ['required', 'url', 'max:255'],
            'admin_url' => ['nullable', 'url', 'max:255'],
            'root_folder' => ['nullable', 'string', 'max:255'],
            'documents_folder' => ['nullable', 'string', 'max:255'],
            'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'service_username' => ['nullable', 'string', 'max:255'],
            'service_password' => [$connection ? 'nullable' : 'nullable', 'string', 'max:1000'],
            'allow_user_credentials' => ['nullable', 'boolean'],
            'calendar_sync_enabled' => ['nullable', 'boolean'],
            'file_browser_enabled' => ['nullable', 'boolean'],
            'users_groups_read_enabled' => ['nullable', 'boolean'],
            'talk_bot_id' => ['nullable', 'integer'],
            'talk_bot_secret' => ['nullable', 'string', 'max:500'],
            'talk_default_conversation_token' => ['nullable', 'string', 'max:64'],
            'talk_bot_features' => ['nullable', 'array'],
            'talk_bot_features.*' => ['string', Rule::in(['reaction', 'no-setup'])],
        ]);
        if ($data['scope'] !== NextcloudConnection::SCOPE_GLOBAL) {
            abort_unless(! empty($data['client_id']), 422, 'A client is required for this connection scope.');
            if (! empty($data['client_site_id'])) {
                abort_unless(\App\Models\Clients\ClientSite::whereKey($data['client_site_id'])->where('client_id', $data['client_id'])->exists(), 422, 'The site must belong to this client.');
            }
            abort_if($data['scope'] === NextcloudConnection::SCOPE_SITE && empty($data['client_site_id']), 422, 'A site is required for site scope.');
        }

        return $data;
    }
}
