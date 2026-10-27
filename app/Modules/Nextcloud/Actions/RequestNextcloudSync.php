<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use App\Modules\Nextcloud\Models\NextcloudUserMapping;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Throwable;

class RequestNextcloudSync
{
    private const PREVIEW_LIMIT = 500;

    public function __construct(private readonly NextcloudReadClient $client) {}

    public function handle(NextcloudConnection $connection, ?User $requestedBy = null, string $operation = 'manual_sync'): NextcloudSyncLog
    {
        $connection->forceFill([
            'last_sync_requested_at' => now(),
        ])->save();

        $log = NextcloudSyncLog::query()->create([
            'connection_id' => $connection->id,
            'operation' => $operation,
            'status' => 'running',
            'credential_source' => 'service',
            'user_id' => $requestedBy?->id,
            'started_at' => now(),
            'message' => 'Reading Nextcloud capabilities, users, groups, calendars, and root folder.',
            'context' => [
                'scope' => $connection->scope,
                'mode' => $connection->mode,
            ],
        ]);

        try {
            $capabilities = $this->client->capabilities($connection);
            $users = $connection->settings['users_groups_read_enabled'] ?? true
                ? $this->client->users($connection)
                : [];
            $groups = $connection->settings['users_groups_read_enabled'] ?? true
                ? $this->client->groups($connection)
                : [];
            $groupMembers = $connection->settings['users_groups_read_enabled'] ?? true
                ? $this->readGroupMembers($connection, $groups)
                : [];
            $calendars = $connection->settings['calendar_sync_enabled'] ?? false
                ? $this->client->calendars($connection)
                : [];
            $files = $connection->settings['file_browser_enabled'] ?? true
                ? $this->client->files($connection)
                : [];
            $identitySummary = $connection->scope !== NextcloudConnection::SCOPE_GLOBAL
                ? $this->syncClientIdentities($connection, $groupMembers)
                : ['seen' => 0, 'changed' => 0, 'failed' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0];
            $eventSummary = $connection->settings['calendar_sync_enabled'] ?? false
                ? $this->syncMappedCalendars($connection)
                : ['seen' => 0, 'changed' => 0, 'failed' => 0, 'imported' => 0, 'pushed' => 0, 'conflicts' => 0];

            $context = [
                'scope' => $connection->scope,
                'mode' => $connection->mode,
                'summary' => [
                    'users' => count($users),
                    'groups' => count($groups),
                    'group_memberships' => collect($groupMembers)->flatten()->count(),
                    'client_contacts_imported' => $identitySummary['imported'],
                    'client_contacts_updated' => $identitySummary['updated'],
                    'calendars' => count($calendars),
                    'files' => count($files),
                    'calendar_events_seen' => $eventSummary['seen'],
                    'calendar_events_changed' => $eventSummary['changed'],
                    'calendar_events_imported' => $eventSummary['imported'],
                    'calendar_events_pushed' => $eventSummary['pushed'],
                    'calendar_event_conflicts' => $eventSummary['conflicts'],
                ],
                'preview' => [
                    'users' => array_slice($users, 0, self::PREVIEW_LIMIT),
                    'groups' => array_slice($groups, 0, self::PREVIEW_LIMIT),
                    'group_members' => collect($groupMembers)
                        ->map(fn (array $members) => array_slice($members, 0, self::PREVIEW_LIMIT))
                        ->all(),
                    'calendars' => array_slice($calendars, 0, self::PREVIEW_LIMIT),
                    'files' => array_slice($files, 0, self::PREVIEW_LIMIT),
                ],
            ];

            $log->forceFill([
                'status' => ($eventSummary['failed'] + $eventSummary['conflicts'] + ($eventSummary['skipped'] ?? 0) + $identitySummary['failed']) > 0 ? 'partial' : 'success',
                'records_seen' => count($users) + count($groups) + count($calendars) + count($files) + $eventSummary['seen'] + $identitySummary['seen'],
                'records_changed' => $eventSummary['changed'] + $identitySummary['changed'],
                'records_failed' => $eventSummary['failed'] + $identitySummary['failed'],
                'finished_at' => now(),
                'message' => $log->status === 'success' ? 'Read sync completed.' : 'Read sync completed with calendar resources requiring review.',
                'context' => $context,
            ])->save();

            $connection->refresh();
            $settings = $connection->settings ?? [];
            $settings['last_read_summary'] = $context['summary'];

            $connection->forceFill([
                'health_status' => $log->status === 'success' ? 'healthy' : 'warning',
                'last_error' => null,
                'last_successful_sync_at' => $log->status === 'success' ? now() : $connection->last_successful_sync_at,
                'capabilities' => $capabilities,
                'settings' => $settings,
            ])->save();
        } catch (Throwable $exception) {
            $log->forceFill([
                'status' => 'failed',
                'records_failed' => 1,
                'finished_at' => now(),
                'message' => $exception->getMessage(),
            ])->save();

            $connection->forceFill([
                'health_status' => 'error',
                'last_error' => $exception->getMessage(),
            ])->save();
        }

        return $log;
    }

    private function readGroupMembers(NextcloudConnection $connection, array $groups): array
    {
        $members = [];

        foreach ($groups as $group) {
            try {
                $members[$group] = $this->client->groupMembers($connection, $group);
            } catch (Throwable) {
                $members[$group] = [];
            }
        }

        return $members;
    }

    private function syncClientIdentities(NextcloudConnection $connection, array $groupMembers): array
    {
        $summary = ['seen' => 0, 'changed' => 0, 'failed' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0];

        if (! $connection->client_id) {
            return $summary;
        }

        $site = $connection->client_site_id
            ? ClientSite::query()
                ->whereKey($connection->client_site_id)
                ->where('client_id', $connection->client_id)
                ->first()
            : null;

        if (! $site) {
            return array_merge($summary, ['failed' => 1]);
        }

        $roleByRemoteUser = [];
        $mappedGroups = $connection->groupMappings()
            ->where('is_active', true)
            ->where('sync_mode', 'nextcloud_to_nexum')
            ->whereNotNull('client_role')
            ->get();

        foreach ($mappedGroups as $mapping) {
            foreach ($groupMembers[$mapping->remote_group_id] ?? [] as $remoteUser) {
                $roleByRemoteUser[$remoteUser] ??= $mapping->client_role;
            }
        }

        foreach ($roleByRemoteUser as $remoteUser => $clientRole) {
            $summary['seen']++;

            $existingMapping = NextcloudUserMapping::query()
                ->where('connection_id', $connection->id)
                ->where('remote_user_id', $remoteUser)
                ->first();

            if (($existingMapping?->metadata['mapping_action'] ?? null) === 'skip') {
                $summary['skipped']++;

                continue;
            }

            $email = filter_var($remoteUser, FILTER_VALIDATE_EMAIL) ? $remoteUser : null;
            $clientUser = $email
                ? ClientUser::query()
                    ->where('email', $email)
                    ->whereHas('site', fn ($query) => $query->where('client_id', $connection->client_id))
                    ->first()
                : null;

            if (! $clientUser) {
                $clientUser = app(ImportNextcloudClientContact::class)->handle($connection, $remoteUser, $email, $clientRole);

                $summary['imported']++;
                $summary['changed']++;
            } elseif ($clientUser->role !== $clientRole || ! $clientUser->active) {
                $clientUser->forceFill([
                    'role' => $clientRole,
                    'active' => true,
                ])->save();

                $summary['updated']++;
                $summary['changed']++;
            }

            app(\App\Modules\Contact\Actions\MigrateClientUsersToContacts::class)->migrateOne($clientUser);

            NextcloudUserMapping::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'remote_user_id' => $remoteUser],
                [
                    'user_id' => null,
                    'remote_username' => $remoteUser,
                    'remote_email' => $email,
                    'identity_type' => 'client_contact',
                    'identity_model_type' => ClientUser::class,
                    'identity_model_id' => $clientUser->id,
                    'is_active' => true,
                    'metadata' => [
                        'client_id' => $connection->client_id,
                        'client_role' => $clientRole,
                        'mapping_action' => $existingMapping?->metadata['mapping_action'] ?? 'group_import',
                    ],
                ]
            );
        }

        return $summary;
    }

    private function syncMappedCalendars(NextcloudConnection $connection): array
    {
        if (! $connection->calendarMappings()->where('is_active', true)->whereNotNull('calendar_id')->exists()) {
            return ['seen' => 0, 'changed' => 0, 'failed' => 0, 'imported' => 0, 'pushed' => 0, 'conflicts' => 0, 'skipped' => 0];
        }

        return app(RunNextcloudCalendarSync::class)->manual($connection);
    }
}
