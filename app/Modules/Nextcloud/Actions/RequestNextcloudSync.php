<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Nextcloud\Models\NextcloudCalendarMapping;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncConflict;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use App\Modules\Nextcloud\Models\NextcloudUserMapping;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class RequestNextcloudSync
{
    private const PREVIEW_LIMIT = 500;

    public function __construct(private readonly NextcloudReadClient $client)
    {
    }

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
                'status' => 'success',
                'records_seen' => count($users) + count($groups) + count($calendars) + count($files) + $eventSummary['seen'] + $identitySummary['seen'],
                'records_changed' => $eventSummary['changed'] + $identitySummary['changed'],
                'records_failed' => $eventSummary['failed'] + $identitySummary['failed'],
                'finished_at' => now(),
                'message' => 'Read sync completed.',
                'context' => $context,
            ])->save();

            $settings = $connection->settings ?? [];
            $settings['last_read_summary'] = $context['summary'];

            $connection->forceFill([
                'health_status' => 'healthy',
                'last_error' => null,
                'last_successful_sync_at' => now(),
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
                $clientUser = ClientUser::query()->create([
                    'client_site_id' => $site->id,
                    'name' => $remoteUser,
                    'email' => $email,
                    'role' => $clientRole,
                    'active' => true,
                ]);

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
        $summary = ['seen' => 0, 'changed' => 0, 'failed' => 0, 'imported' => 0, 'pushed' => 0, 'conflicts' => 0];
        $from = now()->subYear();
        $to = now()->addYears(2);

        $mappings = $connection->calendarMappings()
            ->where('is_active', true)
            ->whereNotNull('calendar_id')
            ->with('calendar')
            ->get();

        foreach ($mappings as $mapping) {
            if (! $mapping instanceof NextcloudCalendarMapping || ! $mapping->calendar) {
                continue;
            }

            if (in_array($mapping->sync_direction, ['two_way', 'pull_only'], true)) {
                foreach ($this->client->calendarEvents($connection, $mapping->remote_calendar_id, $from, $to) as $remote) {
                    $summary['seen']++;
                    $result = $this->importRemoteEvent($connection, $mapping, $remote);
                    $summary[$result]++;
                    if (in_array($result, ['imported', 'pushed'], true)) {
                        $summary['changed']++;
                    }
                }
            }

            if ($connection->canWrite() && in_array($mapping->sync_direction, ['two_way', 'push_only'], true)) {
                $events = CalendarEvent::query()
                    ->where('calendar_id', $mapping->calendar_id)
                    ->whereBetween('starts_at', [$from->copy()->utc(), $to->copy()->utc()])
                    ->where(fn ($query) => $query
                        ->whereNull('external_source')
                        ->orWhere('external_source', 'nextcloud'))
                    ->get();

                foreach ($events as $event) {
                    if ($event->last_synced_at && ! $event->updated_at->greaterThan($event->last_synced_at)) {
                        continue;
                    }

                    $etag = $this->client->putCalendarEvent($connection, $mapping->remote_calendar_id, $event);
                    $event->forceFill([
                        'source' => $event->source ?: 'local',
                        'external_source' => 'nextcloud',
                        'external_calendar_id' => $mapping->remote_calendar_id,
                        'external_event_id' => rtrim($mapping->remote_calendar_id, '/').'/'.($event->external_uid ?: $event->uuid).'.ics',
                        'external_uid' => $event->external_uid ?: $event->uuid,
                        'external_etag' => $etag,
                        'sync_status' => 'synced',
                        'last_synced_at' => now(),
                        'sync_hash' => $this->localHash($event),
                    ])->save();

                    $summary['seen']++;
                    $summary['changed']++;
                    $summary['pushed']++;
                }
            }

            $mapping->forceFill(['last_synced_at' => now()])->save();
        }

        return $summary;
    }

    private function importRemoteEvent(NextcloudConnection $connection, NextcloudCalendarMapping $mapping, array $remote): string
    {
        $event = $remote['event'] ?? [];
        $remoteHash = $this->remoteHash($event);
        $local = CalendarEvent::query()
            ->where('external_source', 'nextcloud')
            ->where('external_calendar_id', $mapping->remote_calendar_id)
            ->where(fn ($query) => $query
                ->where('external_event_id', $remote['href'])
                ->orWhere('external_uid', $event['uid']))
            ->first();

        if ($local && $local->last_synced_at && $local->updated_at->greaterThan($local->last_synced_at) && $local->sync_hash !== $remoteHash) {
            NextcloudSyncConflict::query()->firstOrCreate(
                [
                    'connection_id' => $connection->id,
                    'conflictable_type' => CalendarEvent::class,
                    'conflictable_id' => $local->id,
                    'remote_object_type' => 'calendar_event',
                    'remote_object_id' => $remote['href'],
                    'status' => 'open',
                ],
                [
                    'local_snapshot' => $local->only(['title', 'starts_at', 'ends_at', 'updated_at', 'sync_hash']),
                    'remote_snapshot' => $event,
                    'message' => 'Calendar event changed in both Nexum and Nextcloud since last sync.',
                    'assigned_user_id' => $mapping->user_id,
                ]
            );

            return 'conflicts';
        }

        $startsAt = $event['dtstart']['value'] ?? now();
        $endsAt = $event['dtend']['value'] ?? ($startsAt instanceof Carbon ? $startsAt->copy()->addHour() : now()->addHour());
        $timezone = $event['dtstart']['timezone'] ?? $mapping->calendar?->timezone ?? 'Europe/Oslo';

        $payload = [
            'uuid' => $local?->uuid ?: (string) Str::uuid(),
            'calendar_id' => $mapping->calendar_id,
            'title' => $event['summary'] ?? 'Untitled Nextcloud event',
            'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null,
            'meeting_url' => $event['url'] ?? null,
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $endsAt->copy()->utc(),
            'timezone' => $timezone,
            'all_day' => (bool) ($event['dtstart']['all_day'] ?? false),
            'status' => strtolower($event['status'] ?? 'confirmed'),
            'transparency' => ($event['transp'] ?? null) === 'TRANSPARENT' ? 'free' : 'busy',
            'visibility' => in_array(strtolower($event['class'] ?? ''), ['private', 'confidential'], true) ? strtolower($event['class']) : 'default',
            'created_by' => $local?->created_by ?: $mapping->user_id,
            'updated_by' => $mapping->user_id,
            'source' => 'external',
            'external_source' => 'nextcloud',
            'external_calendar_id' => $mapping->remote_calendar_id,
            'external_event_id' => $remote['href'],
            'external_uid' => $event['uid'],
            'external_etag' => $remote['etag'],
            'sync_status' => 'synced',
            'last_synced_at' => now(),
            'sync_hash' => $remoteHash,
            'metadata' => array_filter([
                'nextcloud_connection_id' => $connection->id,
                'nextcloud_calendar_mapping_id' => $mapping->id,
            ]),
        ];

        if ($local) {
            if ($local->external_etag === $remote['etag'] && $local->sync_hash === $remoteHash) {
                return 'seen';
            }

            $local->forceFill($payload)->save();

            return 'imported';
        }

        CalendarEvent::query()->create($payload);

        return 'imported';
    }

    private function localHash(CalendarEvent $event): string
    {
        return sha1(json_encode([
            $event->title,
            $event->description,
            $event->location,
            $event->meeting_url,
            $event->starts_at?->toIso8601String(),
            $event->ends_at?->toIso8601String(),
            $event->timezone,
            $event->all_day,
            $event->status,
            $event->transparency,
            $event->visibility,
        ]));
    }

    private function remoteHash(array $event): string
    {
        return sha1(json_encode($event));
    }
}
