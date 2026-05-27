<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;

class StoreNextcloudConnection
{
    public function handle(array $data): NextcloudConnection
    {
        $connection = NextcloudConnection::query()->create($this->payload($data));

        $this->enforceDefault($connection);

        return $connection;
    }

    public function payload(array $data, ?NextcloudConnection $existing = null): array
    {
        $scope = $data['scope'];
        $mode = $scope === NextcloudConnection::SCOPE_GLOBAL
            ? $data['mode']
            : NextcloudConnection::MODE_READ_ONLY;

        $payload = [
            'name' => $data['name'],
            'scope' => $scope,
            'mode' => $mode,
            'client_id' => $scope === NextcloudConnection::SCOPE_GLOBAL ? null : ($data['client_id'] ?? null),
            'client_site_id' => $scope === NextcloudConnection::SCOPE_GLOBAL ? null : ($data['client_site_id'] ?? null),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'base_url' => $baseUrl = rtrim($data['base_url'], '/'),
            'admin_url' => isset($data['admin_url']) && $data['admin_url'] !== '' ? rtrim($data['admin_url'], '/') : $baseUrl.'/settings/admin',
            'root_folder' => ($data['root_folder'] ?? '') !== '' ? $data['root_folder'] : null,
            'documents_folder' => ($data['documents_folder'] ?? '') !== '' ? $data['documents_folder'] : null,
            'sync_interval_minutes' => (int) ($data['sync_interval_minutes'] ?? 15),
            'service_username' => ($data['service_username'] ?? '') !== '' ? $data['service_username'] : null,
            'allow_user_credentials' => (bool) ($data['allow_user_credentials'] ?? false),
            'supports_managed_writes' => $mode === NextcloudConnection::MODE_MANAGED,
            'settings' => [
                'calendar_sync_enabled' => (bool) ($data['calendar_sync_enabled'] ?? false),
                'file_browser_enabled' => (bool) ($data['file_browser_enabled'] ?? true),
                'users_groups_read_enabled' => (bool) ($data['users_groups_read_enabled'] ?? true),
            ],
        ];

        if (! empty($data['service_password'])) {
            $payload['service_password'] = $data['service_password'];
            $payload['health_status'] = 'untested';
            $payload['last_error'] = null;
        } elseif (! $existing) {
            $payload['service_password'] = null;
        }

        // Talk bot configuration
        $payload['talk_bot_id'] = isset($data['talk_bot_id']) && $data['talk_bot_id'] !== ''
            ? (int) $data['talk_bot_id']
            : null;

        // Only update the secret if a new value was provided;
        // the password field sends a placeholder when unchanged.
        if (! empty($data['talk_bot_secret']) && $data['talk_bot_secret'] !== str_repeat('•', 8)) {
            $payload['talk_bot_secret'] = $data['talk_bot_secret'];
        } elseif (! $existing) {
            // New connection — set to null if not provided
            $payload['talk_bot_secret'] = null;
        }
        // If updating and no new secret was provided, we simply
        // don't include it in the payload, preserving the existing value.

        $payload['talk_default_conversation_token'] = ($data['talk_default_conversation_token'] ?? '') !== ''
            ? $data['talk_default_conversation_token']
            : null;
        $payload['talk_bot_features'] = $data['talk_bot_features'] ?? [];

        return $payload;
    }

    public function enforceDefault(NextcloudConnection $connection): void
    {
        if (! $connection->is_default) {
            return;
        }

        NextcloudConnection::query()
            ->whereKeyNot($connection->id)
            ->where('scope', $connection->scope)
            ->when($connection->client_id, fn ($query) => $query->where('client_id', $connection->client_id))
            ->when(! $connection->client_id, fn ($query) => $query->whereNull('client_id'))
            ->when($connection->client_site_id, fn ($query) => $query->where('client_site_id', $connection->client_site_id))
            ->when(! $connection->client_site_id, fn ($query) => $query->whereNull('client_site_id'))
            ->update(['is_default' => false]);
    }
}
