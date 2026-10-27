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
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Throwable;

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

    /**
     * Update Talk Bot configuration only (separate form on show page).
     */
    public function updateTalkBot(Request $request, NextcloudConnection $connection)
    {
        app(\App\Modules\Nextcloud\Actions\ManageNextcloudConfiguration::class)->updateTalkBot($request, $connection);

        return redirect()->route('tech.admin.nextcloud.connections.show', $connection)->with('success', 'Talk Bot settings saved.');
    }

    public function testTalkBot(NextcloudConnection $connection)
    {
        try {
            $result = app(\App\Modules\Nextcloud\Actions\TestNextcloudTalkBot::class)->handle($connection, 'nexum-test-'.\Illuminate\Support\Str::uuid());

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable) {
            return response()->json(['success' => false, 'error' => 'Talk test did not return a verified acknowledgement.']);
        }
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

    public function storeUserMapping(Request $request, NextcloudConnection $connection)
    {
        app(\App\Modules\Nextcloud\Actions\ManageNextcloudConfiguration::class)->storeUserMapping($request, $connection);
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Nextcloud user mapping saved.']);
        }

        return back()->with('success', 'Nextcloud user mapping saved.');
    }

    public function storeGroupMapping(Request $request, NextcloudConnection $connection)
    {
        app(\App\Modules\Nextcloud\Actions\ManageNextcloudConfiguration::class)->storeGroupMapping($request, $connection);

        return back()->with('success', 'Nextcloud group mapping saved.');
    }

    public function storeCalendarMapping(Request $request, NextcloudConnection $connection): RedirectResponse
    {
        app(\App\Modules\Nextcloud\Actions\ManageNextcloudCalendarMapping::class)->upsert($request, $connection);

        return back()->with('success', 'Nextcloud calendar mapping saved.');
    }

    public function updateFolders(Request $request, NextcloudConnection $connection)
    {
        app(\App\Modules\Nextcloud\Actions\ManageNextcloudConfiguration::class)->updateFolders($request, $connection);

        return redirect()->route('tech.admin.nextcloud.connections.show', $connection)->with('success', 'Folder saved.');
    }

    public function storeFolderMapping(Request $request, NextcloudConnection $connection)
    {
        app(\App\Modules\Nextcloud\Actions\ManageNextcloudConfiguration::class)->storeFolderMapping($request, $connection);

        return redirect()->route('tech.admin.nextcloud.connections.show', $connection)->with('success', 'Client folder mapping saved.');
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
        app(\App\Modules\Nextcloud\Actions\ManageNextcloudCalendarMapping::class)->remove($mapping);

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
        return app(\App\Modules\Nextcloud\Actions\ValidateNextcloudConnection::class)->handle($request, $connection);
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
