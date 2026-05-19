<?php

namespace App\Modules\Nextcloud\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Nextcloud\Actions\AutoMatchClientFolders;
use App\Modules\Nextcloud\Actions\CheckNextcloudConnectionHealth;
use App\Modules\Nextcloud\Actions\RequestNextcloudSync;
use App\Modules\Nextcloud\Actions\StoreNextcloudConnection;
use App\Modules\Nextcloud\Actions\UpdateNextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudCalendarMapping;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudFolderMapping;
use App\Modules\Nextcloud\Models\NextcloudGroupMapping;
use App\Modules\Nextcloud\Models\NextcloudUserMapping;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;
use Spatie\Permission\Models\Role;

class NextcloudConnectionController extends Controller
{
    public function index(): View
    {
        return view('nextcloud::Admin.connections.index', [
            'connections' => NextcloudConnection::query()
                ->with(['client:id,name', 'site:id,name,client_id', 'syncLogs' => fn ($query) => $query->latest('id')->limit(1)])
                ->withCount(['folderMappings', 'calendarMappings', 'userMappings', 'groupMappings', 'conflicts'])
                ->latest('id')
                ->get(),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'sites' => ClientSite::query()->with('client:id,name')->orderBy('name')->get(['id', 'client_id', 'name']),
        ]);
    }

    public function show(NextcloudConnection $connection, Request $request, NextcloudReadClient $client): View
    {
        $connection->load([
            'client:id,name',
            'site:id,name,client_id',
            'syncLogs' => fn ($query) => $query->latest('id')->limit(5),
            'folderMappings.mappable',
            'userMappings.user:id,name,email',
            'userMappings.identity',
            'groupMappings.role:id,name',
            'groupMappings.client:id,name',
            'calendarMappings.calendar:id,name,type,owner_type,owner_id',
            'calendarMappings.user:id,name,email',
        ]);
        $scopedClient = $connection->scope === NextcloudConnection::SCOPE_GLOBAL ? null : $connection->client;
        $clientContacts = $scopedClient
            ? $scopedClient->contacts()
                ->where('client_users.active', true)
                ->orderBy('client_users.name')
                ->get(['client_users.id', 'client_users.client_site_id', 'client_users.name', 'client_users.email', 'client_users.role'])
                ->load('site.client:id,name')
            : ClientUser::query()
                ->with('site.client:id,name')
                ->where('active', true)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'client_site_id', 'name', 'email', 'role']);
        $folderPath = $this->normalizeFolderPath($request->query('folder_path', $connection->root_folder ?: '/'));
        $folderEntries = [];
        $clientFolderEntries = [];
        $folderBrowserError = null;

        try {
            $folderEntries = collect($client->files($connection, $folderPath))
                ->where('type', 'folder')
                ->values()
                ->all();
            $clientFolderEntries = collect($client->files($connection, $connection->root_folder ?: '/'))
                ->where('type', 'folder')
                ->values()
                ->all();
        } catch (Throwable $exception) {
            $folderBrowserError = $exception->getMessage();
        }

        return view('nextcloud::Admin.connections.show', [
            'connection' => $connection,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'sites' => ClientSite::query()->with('client:id,name')->orderBy('name')->get(['id', 'client_id', 'name']),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'email']),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'scopedClient' => $scopedClient,
            'clientContacts' => $clientContacts,
            'clientRoleOptions' => $this->clientRoleOptions(),
            'calendars' => Calendar::query()->orderBy('name')->get(['id', 'name', 'type', 'owner_type', 'owner_id']),
            'latestSyncLog' => $connection->syncLogs->first(),
            'folderPath' => $folderPath,
            'folderEntries' => $folderEntries,
            'clientFolderEntries' => $clientFolderEntries,
            'folderBrowserError' => $folderBrowserError,
        ]);
    }

    public function store(Request $request, StoreNextcloudConnection $storeConnection): RedirectResponse
    {
        $connection = $storeConnection->handle($this->validated($request));

        return redirect()
            ->route('tech.admin.nextcloud.connections.index')
            ->with('success', "Nextcloud connection {$connection->name} created.");
    }

    public function update(Request $request, NextcloudConnection $connection, UpdateNextcloudConnection $updateConnection): RedirectResponse
    {
        $connection = $updateConnection->handle($connection, $this->validated($request, $connection));

        return redirect()
            ->route('tech.admin.nextcloud.connections.index')
            ->with('success', "Nextcloud connection {$connection->name} updated.");
    }

    public function destroy(NextcloudConnection $connection): RedirectResponse
    {
        $connection->delete();

        return redirect()
            ->route('tech.admin.nextcloud.connections.index')
            ->with('success', 'Nextcloud connection deleted.');
    }

    public function check(NextcloudConnection $connection, CheckNextcloudConnectionHealth $health): RedirectResponse
    {
        $result = $health->handle($connection);

        return redirect()
            ->back()
            ->with($result['success'] ? 'success' : 'warning', $result['success'] ? 'Nextcloud connection is healthy.' : 'Nextcloud health check failed: '.$result['message']);
    }

    public function sync(NextcloudConnection $connection, Request $request, RequestNextcloudSync $sync): RedirectResponse
    {
        $log = $sync->handle($connection, $request->user());
        $summary = $log->context['summary'] ?? [];
        $changed = $summary['calendar_events_changed'] ?? 0;
        $imported = $summary['calendar_events_imported'] ?? 0;
        $pushed = $summary['calendar_events_pushed'] ?? 0;
        $conflicts = $summary['calendar_event_conflicts'] ?? 0;
        $message = $log->status === 'success'
            ? "Nextcloud sync completed. Calendar events changed: {$changed} ({$imported} imported, {$pushed} pushed, {$conflicts} conflicts)."
            : 'Nextcloud sync failed: '.$log->message;

        return redirect()
            ->back()
            ->with($log->status === 'success' ? 'success' : 'warning', $message);
    }

    public function storeUserMapping(Request $request, NextcloudConnection $connection): RedirectResponse
    {
        if ($connection->scope !== NextcloudConnection::SCOPE_GLOBAL) {
            return $this->storeClientUserMapping($request, $connection);
        }

        $data = $request->validate([
            'remote_user_id' => ['required', 'string', 'max:255'],
            'remote_username' => ['nullable', 'string', 'max:255'],
            'remote_email' => ['nullable', 'email', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:'.(new User())->getTable().',id'],
            'client_user_id' => ['nullable', 'integer', 'exists:client_users,id'],
            'identity_type' => ['required', Rule::in(['technician', 'client_contact', 'portal_user', 'external'])],
        ]);

        $clientUser = null;
        if ($data['identity_type'] === 'client_contact') {
            $clientUser = ClientUser::query()->findOrFail($data['client_user_id'] ?? 0);
        } else {
            abort_if(! ($data['user_id'] ?? null), 422, 'A Nexum user is required for this identity type.');
        }

        NextcloudUserMapping::query()->updateOrCreate(
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

        return back()->with('success', 'Nextcloud user mapping saved.');
    }

    public function storeGroupMapping(Request $request, NextcloudConnection $connection): RedirectResponse
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

        NextcloudGroupMapping::query()->updateOrCreate(
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

        return back()->with('success', 'Nextcloud group mapping saved.');
    }

    private function storeClientUserMapping(Request $request, NextcloudConnection $connection): RedirectResponse
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
            NextcloudUserMapping::query()->updateOrCreate(
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

            return back()->with('success', 'Nextcloud user left unmapped.');
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
            $email = $data['remote_email'] ?: (filter_var($data['remote_user_id'], FILTER_VALIDATE_EMAIL) ? $data['remote_user_id'] : null);

            // Imported users are client contacts only; they are not Nexum technicians.
            $clientUser = $email
                ? ClientUser::query()
                    ->where('email', $email)
                    ->whereHas('site', fn ($query) => $query->where('client_id', $connection->client_id))
                    ->first()
                : null;

            $clientUser ??= ClientUser::query()->create([
                    'client_site_id' => $site->id,
                    'name' => $data['remote_username'] ?: $data['remote_user_id'],
                    'email' => $email,
                    'role' => $clientRole,
                    'active' => true,
                ]);

            if ($clientUser->role !== $clientRole) {
                $clientUser->forceFill(['role' => $clientRole])->save();
            }
        }

        NextcloudUserMapping::query()->updateOrCreate(
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

        return back()->with('success', 'Nextcloud client user mapping saved.');
    }

    public function storeCalendarMapping(Request $request, NextcloudConnection $connection): RedirectResponse
    {
        $data = $request->validate([
            'remote_calendar_id' => ['required', 'string', 'max:500'],
            'remote_display_name' => ['nullable', 'string', 'max:255'],
            'calendar_id' => ['nullable', 'integer', 'exists:calendars,id'],
            'user_id' => ['nullable', 'integer', 'exists:'.(new User())->getTable().',id'],
            'sync_direction' => ['required', Rule::in(['two_way', 'pull_only', 'push_only'])],
        ]);

        NextcloudCalendarMapping::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'remote_calendar_id' => $data['remote_calendar_id']],
            [
                'calendar_id' => $data['calendar_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'remote_display_name' => $data['remote_display_name'] ?? null,
                'sync_direction' => $data['sync_direction'],
                'is_active' => true,
            ]
        );

        return back()->with('success', 'Nextcloud calendar mapping saved.');
    }

    public function updateFolders(Request $request, NextcloudConnection $connection): RedirectResponse
    {
        $data = $request->validate([
            'folder_type' => ['required', Rule::in(['root', 'documents'])],
            'remote_path' => ['required', 'string', 'max:500'],
        ]);

        $field = $data['folder_type'] === 'root' ? 'root_folder' : 'documents_folder';
        $connection->forceFill([$field => $this->normalizeFolderPath($data['remote_path'])])->save();

        return redirect()
            ->route('tech.admin.nextcloud.connections.show', $connection)
            ->with('success', ucfirst($data['folder_type']).' folder saved.');
    }

    public function storeFolderMapping(Request $request, NextcloudConnection $connection): RedirectResponse
    {
        $isGlobal = $connection->scope === NextcloudConnection::SCOPE_GLOBAL;
        $data = $request->validate([
            'client_id' => [$isGlobal ? 'required' : 'nullable', 'integer', 'exists:clients,id'],
            'purpose' => ['required', Rule::in(['client_files', 'client_documents'])],
            'remote_path' => ['required', 'string', 'max:500'],
        ]);

        $clientId = $isGlobal ? $data['client_id'] : $connection->client_id;
        abort_if(! $clientId, 422, 'Client folder mappings require a client context.');

        NextcloudFolderMapping::query()->updateOrCreate(
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

        return redirect()
            ->route('tech.admin.nextcloud.connections.show', $connection)
            ->with('success', 'Client folder mapping saved.');
    }

    public function autoMatchClientFolders(NextcloudConnection $connection, AutoMatchClientFolders $autoMatch): RedirectResponse
    {
        $result = $autoMatch->handle($connection);

        return redirect()
            ->route('tech.admin.nextcloud.connections.show', $connection)
            ->with($result['status'], $result['message']);
    }

    public function destroyUserMapping(NextcloudUserMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return back()->with('success', 'Nextcloud user mapping removed.');
    }

    public function destroyGroupMapping(NextcloudGroupMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return back()->with('success', 'Nextcloud group mapping removed.');
    }

    public function destroyCalendarMapping(NextcloudCalendarMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return back()->with('success', 'Nextcloud calendar mapping removed.');
    }

    public function destroyFolderMapping(NextcloudFolderMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        return redirect()
            ->route('tech.admin.nextcloud.connections.show', $mapping->connection)
            ->with('success', 'Nextcloud folder mapping removed.');
    }

    private function validated(Request $request, ?NextcloudConnection $connection = null): array
    {
        return $request->validate([
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
        ]);
    }

    private function normalizeFolderPath(?string $path): string
    {
        $path = trim((string) $path);

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
