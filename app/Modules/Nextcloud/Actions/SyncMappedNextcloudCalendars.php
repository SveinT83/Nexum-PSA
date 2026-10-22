<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\ApplyExternalCalendarEvent;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Nextcloud\Models\NextcloudCalendarMapping;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncConflict;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class SyncMappedNextcloudCalendars
{
    private array $summary;

    private float $deadline;

    private ?array $mappingIds;

    private string $token;

    public function __construct(private readonly NextcloudReadClient $client) {}

    public function handle(NextcloudConnection $connection, ?array $mappingIds, string $token): array
    {
        $this->summary = ['seen' => 0, 'changed' => 0, 'imported' => 0, 'pushed' => 0, 'conflicts' => 0, 'skipped' => 0, 'failed' => 0];
        $this->deadline = microtime(true) + 240;
        $this->mappingIds = $mappingIds;
        $this->token = $token;
        $mappings = $connection->calendarMappings()->where('is_active', true)->whereNotNull('calendar_id')
            ->when($mappingIds !== null, fn ($q) => $q->whereIn('id', $mappingIds))
            ->with(['calendar', 'user'])->get();
        foreach ($mappings as $mapping) {
            $this->guard($connection, $token);
            if (! $mapping->calendar || ! $mapping->calendar->is_active
                || $connection->scope !== 'global'
                || $mapping->calendar->owner_type !== User::class
                || (int) $mapping->calendar->owner_id !== (int) $mapping->user_id
                || ! $mapping->user || $mapping->user->status !== User::STATUS_ACTIVE) {
                $this->conflict($connection, $mapping, null, null, 'Calendar mapping owner or scope requires review.');

                continue;
            }
            try {
                $from = now()->subYear()->startOfDay();
                $to = now()->addYears(2)->endOfDay();
                // Even push-only mappings read first: resource identity and duplicates must be known.
                $resources = $this->client->calendarEvents($connection, $mapping->remote_calendar_id, $from, $to);
                $uidCounts = array_count_values(array_filter(array_map(fn ($r) => $r['event']['uid'] ?? null, $resources)));
                $remoteUids = [];
                $blocked = [];
                foreach ($resources as $resource) {
                    $this->guard($connection, $token);
                    $this->summary['seen']++;
                    $this->assertMapping($mapping);
                    $resource = $this->normalizeResource($resource, $mapping);
                    $remote = $resource['event'];
                    $uid = $remote['uid'] ?? null;
                    if (! $uid || ($remote['__invalid'] ?? false) || ($remote['__series'] ?? false)
                        || ! isset($remote['dtstart']['value'], $remote['dtend']['value'])
                        || $remote['dtend']['value']->lte($remote['dtstart']['value'])) {
                        $this->conflict($connection, $mapping, null, $resource, 'Unsupported recurrence or invalid event dates retained in Nextcloud.');
                        if ($uid) {
                            $remoteUids[$uid] = true;
                        }

                        continue;
                    }
                    if (($uidCounts[$uid] ?? 0) > 1) {
                        $remoteUids[$uid] = true;
                        $this->conflict($connection, $mapping, null, $resource, 'Repeated remote UID requires reconciliation.');

                        continue;
                    }
                    $remoteUids[$uid] = true;
                    $local = CalendarEvent::withTrashed()->where('external_source', 'nextcloud')
                        ->where('external_calendar_id', $mapping->remote_calendar_id)
                        ->where(fn ($q) => $q->where('external_uid', $uid)->orWhere('external_event_id', $resource['href']))
                        ->get();
                    if ($local->count() > 1) {
                        $this->conflict($connection, $mapping, null, $resource, 'Several local events claim the remote identity.');

                        continue;
                    }
                    $local = $local->first();
                    if (! $local) {
                        // A new resource uses the local UUID. Only a persisted matching
                        // delivery intent can adopt it after an unknown PUT outcome.
                        $local = CalendarEvent::where('calendar_id', $mapping->calendar_id)->where('uuid', $uid)
                            ->whereNull('external_source')->get()->first(fn ($e) => ($e->metadata['nextcloud_pending_write'] ?? false)
                                && (int) ($e->metadata['nextcloud_pending_connection_id'] ?? 0) === (int) $connection->id
                                && (int) ($e->metadata['nextcloud_pending_mapping_id'] ?? 0) === (int) $mapping->id);
                    }
                    if ($local && ($local->trashed() || (int) $local->calendar_id !== (int) $mapping->calendar_id
                        || $local->source === 'workday_absence' || $local->series_id
                        || (isset($local->metadata['nextcloud_connection_id'])
                            && (int) $local->metadata['nextcloud_connection_id'] !== (int) $connection->id))) {
                        $this->conflict($connection, $mapping, $local, $resource, 'Event ownership or deletion requires reconciliation.');

                        continue;
                    }
                    if (! $local) {
                        $candidates = CalendarEvent::withTrashed()->where('calendar_id', $mapping->calendar_id)
                            ->whereNull('external_source')->where('title', $remote['summary'] ?? '')
                            ->where('starts_at', $remote['dtstart']['value']->copy()->utc())
                            ->where('ends_at', $remote['dtend']['value']->copy()->utc())->get();
                        if ($candidates->isNotEmpty()) {
                            foreach ($candidates as $candidate) {
                                $blocked[$candidate->id] = true;
                                $this->conflict($connection, $mapping, $candidate, $resource, 'Unlinked local duplicate candidate; explicit identity reconciliation required.');
                            }

                            continue;
                        }
                        if ($mapping->sync_direction !== 'push_only') {
                            $this->pull($connection, $mapping, null, $resource);
                        }

                        continue;
                    }
                    $blocked[$local->id] = true;
                    $remoteHash = $this->remoteHash($remote);
                    $localHash = $this->localHash($local);
                    $safe = $remote['__write_safe'] ?? false;
                    if ($localHash === $remoteHash) {
                        $this->acknowledge($connection, $mapping, $local, $resource, $remoteHash, $safe);

                        continue;
                    }
                    // A durable open conflict is never silently resolved by another tick.
                    if ($this->hasConflict($connection, $mapping, $local, $resource)) {
                        $this->summary['conflicts']++;

                        continue;
                    }
                    $versioned = ($local->metadata['nextcloud_hash_version'] ?? null) === 1;
                    $localChanged = $versioned ? $localHash !== $local->sync_hash
                        : (! $local->last_synced_at || $local->updated_at->gt($local->last_synced_at));
                    $remoteChanged = $versioned ? $remoteHash !== $local->sync_hash
                        : $local->external_etag !== ($resource['etag'] ?? null);
                    if ($local->metadata['nextcloud_pending_write'] ?? false) {
                        $this->conflict($connection, $mapping, $local, $resource, 'Unknown provider write outcome requires reconciliation.');
                    } elseif ($localChanged && $remoteChanged) {
                        $this->conflict($connection, $mapping, $local, $resource, 'Event changed in both calendars; no provider write performed.');
                    } elseif ($localChanged) {
                        if ($connection->canWrite() && $mapping->sync_direction !== 'pull_only' && $safe) {
                            $this->push($connection, $mapping, $local, $resource, $token);
                        } else {
                            $this->conflict($connection, $mapping, $local, $resource, 'Local change cannot safely round-trip this provider resource.');
                        }
                    } elseif ($mapping->sync_direction !== 'push_only') {
                        $this->pull($connection, $mapping, $local, $resource);
                    } else {
                        $this->conflict($connection, $mapping, $local, $resource, 'Remote change retained by push-only mapping.');
                    }
                }
                if ($connection->canWrite() && $mapping->sync_direction !== 'pull_only') {
                    // A local unbound event needs one unambiguous destination. An automatic
                    // allowlist is a reviewed destination; manual all-mapping runs still refuse ambiguity.
                    $destinations = $mappings->where('calendar_id', $mapping->calendar_id)
                        ->whereIn('sync_direction', ['two_way', 'push_only'])->count();
                    $events = CalendarEvent::where('calendar_id', $mapping->calendar_id)
                        ->whereBetween('starts_at', [$from->copy()->utc(), $to->copy()->utc()])
                        ->where(fn ($q) => $q->whereNull('external_source')->orWhere('external_source', 'nextcloud'))->get();
                    foreach ($events as $event) {
                        $this->guard($connection, $token);
                        $this->assertMapping($mapping);
                        if (isset($blocked[$event->id]) || $event->source === 'workday_absence') {
                            continue;
                        }
                        if ($event->external_source === 'nextcloud') {
                            // Never re-home bound events, even when another mapping shares this calendar.
                            if ($event->external_calendar_id !== $mapping->remote_calendar_id) {
                                continue;
                            }
                            if (! isset($remoteUids[$event->external_uid ?? ''])) {
                                $this->conflict($connection, $mapping, $event, null, 'Bound remote event absent from inventory; deletion or move requires review.');
                            }

                            continue;
                        }
                        if ($destinations !== 1 || $event->series_id || $event->participants()->exists()
                            || ($event->metadata['nextcloud_pending_write'] ?? false)) {
                            $this->conflict($connection, $mapping, $event, null, 'Unbound event has ambiguous destination, unsupported data or unresolved delivery.');

                            continue;
                        }
                        $this->push($connection, $mapping, $event, null, $token);
                    }
                }
                $mapping->forceFill(['last_synced_at' => now()])->save();
            } catch (Throwable) {
                $this->summary['failed']++;
            }
        }

        return $this->summary;
    }

    private function guard(NextcloudConnection $connection, string $token): void
    {
        if (microtime(true) > $this->deadline) {
            throw new RuntimeException('Calendar sync execution budget exceeded.');
        }
        app(NextcloudCalendarLease::class)->assertOwned($connection->id, $token);
        $connection->refresh();
        if (! $connection->is_active || ! ($connection->settings['calendar_sync_enabled'] ?? false)
            || ($this->mappingIds !== null && (! DispatchDueNextcloudCalendars::enabled($connection)
                || DispatchDueNextcloudCalendars::mappingIds($connection) !== $this->mappingIds))) {
            throw new RuntimeException('Calendar sync paused during execution.');
        }
    }

    private function assertMapping(NextcloudCalendarMapping $mapping): void
    {
        $fields = ['connection_id', 'calendar_id', 'user_id', 'remote_calendar_id', 'sync_direction', 'is_active'];
        app(NextcloudCalendarLease::class)->assertCalendarOwned($mapping->calendar_id, $this->token);
        $fresh = $mapping->fresh();
        $calendarFields = ['owner_type', 'owner_id', 'is_active', 'timezone'];
        if (! $fresh || $fresh->only($fields) !== $mapping->only($fields)
            || $mapping->calendar->fresh()?->only($calendarFields) !== $mapping->calendar->only($calendarFields)
            || $mapping->user->fresh()?->status !== User::STATUS_ACTIVE) {
            throw new RuntimeException('Calendar mapping changed during synchronization.');
        }
    }

    private function normalizeResource(array $resource, NextcloudCalendarMapping $mapping): array
    {
        if ($resource['event']['dtstart']['all_day'] ?? false) {
            foreach (['dtstart', 'dtend'] as $key) {
                if (isset($resource['event'][$key]['value'])) {
                    $date = $resource['event'][$key]['value']->format('Y-m-d');
                    $resource['event'][$key]['value'] = Carbon::parse($date, $mapping->calendar->timezone)->startOfDay();
                    $resource['event'][$key]['timezone'] = $mapping->calendar->timezone;
                }
            }
        }

        return $resource;
    }

    private function pull(NextcloudConnection $connection, NextcloudCalendarMapping $mapping, ?CalendarEvent $local, array $resource): void
    {
        $remote = $resource['event'];
        $safe = $remote['__write_safe'] ?? false;
        $data = $this->fields($remote, $mapping);
        $data += [
            'calendar_id' => $mapping->calendar_id, 'created_by' => $local?->created_by ?: $mapping->user_id,
            'updated_by' => $mapping->user_id, 'source' => $local?->source ?: 'external',
        ];
        $data = array_merge($data, $this->binding($connection, $mapping, $resource, $this->remoteHash($remote), $safe));
        app(ApplyExternalCalendarEvent::class)->handle($local, $data);
        $this->summary['imported']++;
        $this->summary['changed']++;
        if (! $safe) {
            $this->summary['skipped']++;
        }
    }

    private function acknowledge(NextcloudConnection $connection, NextcloudCalendarMapping $mapping, CalendarEvent $event, array $resource, string $hash, bool $safe): void
    {
        $data = $this->binding($connection, $mapping, $resource, $hash, $safe);
        // Stable equal events need no timestamp write on subsequent cycles.
        if ($event->external_etag !== ($resource['etag'] ?? null) || $event->sync_hash !== $hash
            || ($event->metadata['nextcloud_hash_version'] ?? null) !== 1
            || ($event->metadata['nextcloud_pending_write'] ?? false)) {
            app(ApplyExternalCalendarEvent::class)->handle($event, $data);
        }
        if (! $safe) {
            $this->summary['skipped']++;
        }
    }

    private function push(NextcloudConnection $connection, NextcloudCalendarMapping $mapping, CalendarEvent $event, ?array $remote, string $token): void
    {
        if ($remote && empty($remote['etag'])) {
            $this->conflict($connection, $mapping, $event, $remote, 'Provider ETag missing; conditional update refused.');

            return;
        }
        if ($this->hasConflict($connection, $mapping, $event, $remote)) {
            $this->summary['conflicts']++;

            return;
        }
        // Persist delivery intent before touching the provider. A crash or timeout is
        // reconciled against the next inventory, never blindly repeated.
        $event = app(ApplyExternalCalendarEvent::class)->handle($event, [
            'metadata' => ['nextcloud_pending_write' => true,
                'nextcloud_pending_connection_id' => $connection->id, 'nextcloud_pending_mapping_id' => $mapping->id],
            'sync_status' => 'pending_write',
        ] + ($remote ? [
            'external_event_id' => $remote['href'], 'external_etag' => $remote['etag'],
        ] : []));
        $this->guard($connection, $token);
        $this->assertMapping($mapping);
        if (! $connection->canWrite()) {
            throw new RuntimeException('Calendar provider writes were paused.');
        }
        $this->client->putCalendarEvent($connection, $mapping->remote_calendar_id, $event);
        $href = $remote['href'] ?? rtrim($mapping->remote_calendar_id, '/').'/'.rawurlencode($event->uuid).'.ics';
        $readBack = $this->normalizeResource($this->client->calendarEvent($connection, $mapping->remote_calendar_id, $href), $mapping);
        if (($readBack['event']['uid'] ?? null) !== ($event->external_uid ?: $event->uuid)
            || empty($readBack['etag']) || ! ($readBack['event']['__write_safe'] ?? false)
            || $this->localHash($event) !== $this->remoteHash($readBack['event'])) {
            $this->conflict($connection, $mapping, $event, $readBack, 'Provider read-back differs from intended event.');

            return;
        }
        app(ApplyExternalCalendarEvent::class)->handle($event, $this->binding(
            $connection, $mapping, $readBack, $this->localHash($event), true,
        ));
        $this->summary['seen']++;
        $this->summary['changed']++;
        $this->summary['pushed']++;
    }

    private function binding(NextcloudConnection $connection, NextcloudCalendarMapping $mapping, array $resource, string $hash, bool $safe): array
    {
        return [
            'external_source' => 'nextcloud', 'external_calendar_id' => $mapping->remote_calendar_id,
            'external_event_id' => $resource['href'], 'external_uid' => $resource['event']['uid'],
            'external_etag' => $resource['etag'] ?? null, 'sync_hash' => $hash,
            'sync_status' => $safe ? 'synced' : 'read_only', 'last_synced_at' => now(),
            'metadata' => ['nextcloud_connection_id' => $connection->id, 'nextcloud_calendar_mapping_id' => $mapping->id,
                'nextcloud_hash_version' => 1, 'nextcloud_pending_write' => false, 'nextcloud_write_safe' => $safe],
        ];
    }

    private function fields(array $event, NextcloudCalendarMapping $mapping): array
    {
        $visibility = strtolower($event['class'] ?? 'public');

        return [
            'title' => $event['summary'] ?? 'Untitled Nextcloud event', 'description' => $event['description'] ?? null,
            'location' => $event['location'] ?? null, 'meeting_url' => $event['url'] ?? null,
            'starts_at' => $event['dtstart']['value']->copy()->utc(), 'ends_at' => $event['dtend']['value']->copy()->utc(),
            'timezone' => $event['dtstart']['timezone'] ?? $mapping->calendar->timezone ?? 'UTC',
            'all_day' => (bool) ($event['dtstart']['all_day'] ?? false),
            'status' => strtolower($event['status'] ?? 'confirmed'),
            'transparency' => strtoupper($event['transp'] ?? 'OPAQUE') === 'TRANSPARENT' ? 'free' : 'busy',
            'visibility' => in_array($visibility, ['private', 'confidential'], true) ? $visibility : 'default',
        ];
    }

    private function localHash(CalendarEvent $event): string
    {
        return $this->hash([
            $event->title, $event->description, $event->location, $event->meeting_url,
            $event->starts_at, $event->ends_at, (bool) $event->all_day,
            $event->status ?: 'confirmed', $event->transparency ?: 'busy', $event->visibility ?: 'default',
        ]);
    }

    private function remoteHash(array $event): string
    {
        return $this->hash([
            $event['summary'] ?? 'Untitled Nextcloud event', $event['description'] ?? null,
            $event['location'] ?? null, $event['url'] ?? null,
            $event['dtstart']['value'] ?? null, $event['dtend']['value'] ?? null,
            (bool) ($event['dtstart']['all_day'] ?? false), strtolower($event['status'] ?? 'confirmed'),
            strtoupper($event['transp'] ?? 'OPAQUE') === 'TRANSPARENT' ? 'free' : 'busy',
            in_array(strtolower($event['class'] ?? ''), ['private', 'confidential'], true) ? strtolower($event['class']) : 'default',
        ]);
    }

    private function hash(array $values): string
    {
        return hash('sha256', json_encode(array_map(fn ($v) => $v instanceof Carbon
            ? $v->copy()->utc()->format('Y-m-d\TH:i:s\Z')
            : (is_string($v) ? ($v === '' ? null : str_replace("\r\n", "\n", $v)) : $v), $values)));
    }

    private function hasConflict(NextcloudConnection $connection, NextcloudCalendarMapping $mapping, CalendarEvent $event, ?array $resource): bool
    {
        return NextcloudSyncConflict::where('connection_id', $connection->id)->where('status', 'open')
            ->where('conflictable_type', CalendarEvent::class)->where('conflictable_id', $event->id)->exists();
    }

    private function conflict(NextcloudConnection $connection, NextcloudCalendarMapping $mapping, ?CalendarEvent $event, ?array $resource, string $message): void
    {
        NextcloudSyncConflict::firstOrCreate([
            'connection_id' => $connection->id, 'conflictable_type' => CalendarEvent::class,
            'conflictable_id' => $event?->id, 'remote_object_type' => 'calendar_event',
            'remote_object_id' => $resource['href'] ?? $mapping->remote_calendar_id, 'status' => 'open',
        ], [
            // Keep conflict logs free of private titles, bodies, locations and attendee data.
            'local_snapshot' => ['event_id' => $event?->id, 'mapping_id' => $mapping->id],
            'remote_snapshot' => ['uid' => $resource['event']['uid'] ?? null],
            'message' => $message, 'assigned_user_id' => $mapping->user_id,
        ]);
        $this->summary['conflicts']++;
    }
}
