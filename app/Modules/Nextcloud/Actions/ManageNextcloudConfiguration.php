<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudFolderMapping;
use App\Modules\Nextcloud\Models\NextcloudGroupMapping;
use App\Modules\Nextcloud\Models\NextcloudUserMapping;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shared native configuration/mapping mutations; transports own only their responses. */
class ManageNextcloudConfiguration
{
    public function storeUserMapping(Request $request, NextcloudConnection $connection)
    {
        if ($connection->scope !== NextcloudConnection::SCOPE_GLOBAL) {
            return $this->storeClientUserMapping($request, $connection);
        }

        $data = $request->validate([
            'remote_user_id' => ['required', 'string', 'max:255'],
            'remote_username' => ['nullable', 'string', 'max:255'],
            'remote_email' => ['nullable', 'email', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:'.(new User)->getTable().',id'],
            'client_user_id' => ['nullable', 'integer', 'exists:client_users,id'],
            'identity_type' => ['required', Rule::in(['technician', 'client_contact', 'portal_user', 'external'])],
        ]);

        $clientUser = null;
        if ($data['identity_type'] === 'client_contact') {
            $clientUser = ClientUser::query()->findOrFail($data['client_user_id'] ?? 0);
        } else {
            abort_if(! ($data['user_id'] ?? null), 422, 'A Nexum user is required for this identity type.');
        }

        $mapping = NextcloudUserMapping::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'remote_user_id' => $data['remote_user_id']],
            [
                'user_id' => $clientUser ? null : $data['user_id'],
                'remote_username' => $data['remote_username'] ?? $data['remote_user_id'],
                'remote_email' => $data['remote_email'] ?? null,
                'identity_type' => $data['identity_type'],
                'identity_model_type' => $clientUser ? ClientUser::class : null,
                'identity_model_id' => $clientUser?->id,
                'is_active' => true,
                'metadata' => $clientUser ? ['client_id' => $clientUser->site?->client_id] : null,
            ]
        );

        return $mapping;
    }

    public function storeGroupMapping(Request $request, NextcloudConnection $connection)
    {
        $isGlobal = $connection->scope === NextcloudConnection::SCOPE_GLOBAL;

        $data = $request->validate([
            'remote_group_id' => ['required', 'string', 'max:255'],
            'remote_group_name' => ['nullable', 'string', 'max:255'],
            'role_id' => [$isGlobal ? 'required' : 'nullable', 'integer', 'exists:roles,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'client_role' => [$isGlobal ? 'nullable' : 'required', Rule::in(array_keys($this->clientRoleOptions()))],
            'sync_mode' => ['required', Rule::in(['preview_only', 'nextcloud_to_nexum', 'nexum_to_nextcloud'])],
            'is_managed' => ['nullable', 'boolean'],
        ]);

        $clientId = $isGlobal ? ($data['client_id'] ?? null) : $connection->client_id;

        $mapping = NextcloudGroupMapping::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'remote_group_id' => $data['remote_group_id']],
            [
                'role_id' => $isGlobal ? $data['role_id'] : null,
                'client_id' => $clientId,
                'client_role' => $data['client_role'] ?? null,
                'remote_group_name' => $data['remote_group_name'] ?? $data['remote_group_id'],
                'sync_mode' => $data['sync_mode'],
                'is_managed' => (bool) ($data['is_managed'] ?? false),
                'is_active' => true,
            ]
        );

        return $mapping;
    }

    private function storeClientUserMapping(Request $request, NextcloudConnection $connection)
    {
        abort_if(! $connection->client_id, 422, 'Client-scoped Nextcloud connections must be linked to a client before users can be mapped.');

        $data = $request->validate([
            'remote_user_id' => ['required', 'string', 'max:255'],
            'remote_username' => ['nullable', 'string', 'max:255'],
            'remote_email' => ['nullable', 'email', 'max:255'],
            'client_user_id' => ['nullable', 'integer', 'exists:client_users,id'],
            'mapping_action' => ['required', Rule::in(['skip', 'map_existing', 'import'])],
            'client_role' => ['nullable', Rule::in(array_keys($this->clientRoleOptions()))],
        ]);

        if ($data['mapping_action'] === 'skip') {
            $mapping = NextcloudUserMapping::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'remote_user_id' => $data['remote_user_id']],
                [
                    'user_id' => null,
                    'remote_username' => $data['remote_username'] ?? $data['remote_user_id'],
                    'remote_email' => $data['remote_email'] ?? (filter_var($data['remote_user_id'], FILTER_VALIDATE_EMAIL) ? $data['remote_user_id'] : null),
                    'identity_type' => 'client_contact',
                    'identity_model_type' => null,
                    'identity_model_id' => null,
                    'is_active' => false,
                    'metadata' => [
                        'client_id' => $connection->client_id,
                        'mapping_action' => 'skip',
                    ],
                ]
            );

            return $mapping;
        }

        $clientRole = $data['client_role'] ?? 'contact';

        if ($data['mapping_action'] === 'map_existing') {
            $clientUser = ClientUser::query()
                ->whereKey($data['client_user_id'] ?? 0)
                ->whereHas('site', fn ($query) => $query->where('client_id', $connection->client_id))
                ->firstOrFail();
        } else {
            abort_if(! $connection->client_site_id, 422, 'Set a default import site on the Nextcloud connection before importing client users.');

            $site = ClientSite::query()
                ->whereKey($connection->client_site_id)
                ->where('client_id', $connection->client_id)
                ->firstOrFail();
            $email = ($data['remote_email'] ?? null) ?: (filter_var($data['remote_user_id'], FILTER_VALIDATE_EMAIL) ? $data['remote_user_id'] : null);

            // Imported users are client contacts only; they are not Nexum technicians.
            $clientUser = $email
                ? ClientUser::query()
                    ->where('email', $email)
                    ->whereHas('site', fn ($query) => $query->where('client_id', $connection->client_id))
                    ->first()
                : null;

            $clientUser = app(ImportNextcloudClientContact::class)->handle($connection,
                ($data['remote_username'] ?? null) ?: $data['remote_user_id'], $email, $clientRole);

            if ($clientUser->role !== $clientRole) {
                $clientUser->forceFill(['role' => $clientRole])->save();
            }
        }

        $mapping = NextcloudUserMapping::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'remote_user_id' => $data['remote_user_id']],
            [
                'user_id' => null,
                'remote_username' => $data['remote_username'] ?? $data['remote_user_id'],
                'remote_email' => $data['remote_email'] ?? (filter_var($data['remote_user_id'], FILTER_VALIDATE_EMAIL) ? $data['remote_user_id'] : null),
                'identity_type' => 'client_contact',
                'identity_model_type' => ClientUser::class,
                'identity_model_id' => $clientUser->id,
                'is_active' => true,
                'metadata' => [
                    'client_id' => $connection->client_id,
                    'client_role' => $clientRole,
                    'mapping_action' => $data['mapping_action'],
                ],
            ]
        );

        return $mapping;
    }

    public function storeFolderMapping(Request $request, NextcloudConnection $connection)
    {
        $isGlobal = $connection->scope === NextcloudConnection::SCOPE_GLOBAL;
        $data = $request->validate([
            'client_id' => [$isGlobal ? 'required' : 'nullable', 'integer', 'exists:clients,id'],
            'purpose' => ['required', Rule::in(['client_files', 'client_documents'])],
            'remote_path' => ['required', 'string', 'max:500'],
        ]);

        $clientId = $isGlobal ? $data['client_id'] : $connection->client_id;
        abort_if(! $clientId, 422, 'Client folder mappings require a client context.');

        $mapping = NextcloudFolderMapping::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'mappable_type' => Client::class,
                'mappable_id' => $clientId,
                'purpose' => $isGlobal ? $data['purpose'] : 'client_documents',
            ],
            [
                'remote_path' => $this->normalizeFolderPath($data['remote_path']),
                'is_active' => true,
                'auto_created' => false,
            ]
        );

        return $mapping;
    }

    public function updateFolders(Request $request, NextcloudConnection $connection)
    {
        $data = $request->validate([
            'folder_type' => ['required', Rule::in(['root', 'documents'])],
            'remote_path' => ['required', 'string', 'max:500'],
        ]);

        $field = $data['folder_type'] === 'root' ? 'root_folder' : 'documents_folder';
        $connection->forceFill([$field => $this->normalizeFolderPath($data['remote_path'])])->save();

        return $connection->fresh();
    }

    public function updateTalkBot(Request $request, NextcloudConnection $connection)
    {
        $data = $request->validate([
            'talk_bot_id' => ['nullable', 'integer'],
            'talk_bot_secret' => ['nullable', 'string', 'max:500'],
            'talk_default_conversation_token' => ['nullable', 'string', 'max:64'],
            'talk_bot_features' => ['nullable', 'array'],
            'talk_bot_features.*' => ['string', Rule::in(['reaction', 'no-setup'])],
        ]);

        $connection->talk_bot_id = isset($data['talk_bot_id']) && $data['talk_bot_id'] !== ''
            ? (int) $data['talk_bot_id']
            : null;

        if (! empty($data['talk_bot_secret']) && $data['talk_bot_secret'] !== str_repeat('•', 8)) {
            $connection->talk_bot_secret = $data['talk_bot_secret'];
        }

        $connection->talk_default_conversation_token = ($data['talk_default_conversation_token'] ?? '') !== ''
            ? $data['talk_default_conversation_token']
            : null;
        $connection->talk_bot_features = $data['talk_bot_features'] ?? [];
        $connection->save();

        return $connection->fresh();
    }

    private function normalizeFolderPath(?string $path): string
    {
        $path = trim((string) $path);
        abort_if(preg_match('~(?:^|/)\.\.?(?:/|$)|[\\\\\x00-\x1f?#]~', rawurldecode($path)), 422, 'Use a relative Nextcloud folder path without traversal.');

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/'.trim($path, '/');
    }

    private function clientRoleOptions(): array
    {
        return [
            'client_admin' => 'Client admin',
            'site_admin' => 'Site admin',
            'viewer' => 'Viewer',
            'contact' => 'Contact',
        ];
    }
}
