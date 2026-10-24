<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\ApplyExternalCalendarEvent;
use App\Modules\Calendar\Actions\ProjectWorkdayAbsence;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarVisibility;
use App\Modules\Calendar\Support\CalendarApiContract;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncConflict;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Reconcile exact supported identities. Unknown delivery stays durable until observed and acknowledged. */
class ReconcileNextcloudCalendarConflict
{
    public function preview(User $actor, NextcloudConnection $connection, NextcloudSyncConflict $conflict, string $resolution, ?int $eventId, ?string $expires = null): array
    {
        $state = $this->inspect($actor, $connection, $conflict, $resolution, $eventId);
        $expires ??= now()->addMinutes(10)->toIso8601String();
        abort_if(Carbon::parse($expires)->isPast() || Carbon::parse($expires)->gt(now()->addMinutes(11)), 409, 'Preview expired.');
        $data = ['connection_id' => $connection->id, 'conflict_id' => $conflict->id, 'event_id' => $state['event']->id,
            'mapping_id' => $state['mapping']->id, 'resolution' => $resolution,
            'uid' => $state['resource']['event']['uid'], 'href' => $state['resource']['href'], 'etag' => $state['resource']['etag'],
            'fingerprint' => $state['fingerprint'], 'expires_at' => $expires,
            'unknown_delivery' => (bool) data_get($state['event']->metadata, 'nextcloud_pending_write', false),
            'provider_write_required' => $resolution === 'keep_local' && $state['local_hash'] !== $state['remote_hash']];
        $data['preview'] = hash_hmac('sha256', json_encode([$actor->id, $data]), config('app.key'));

        return $data;
    }

    public function verify(User $actor, NextcloudConnection $connection, NextcloudSyncConflict $conflict, array $input): array
    {
        $current = $this->preview($actor, $connection, $conflict, $input['resolution'], $input['event_id'], $input['expires_at']);
        abort_unless(hash_equals($current['fingerprint'], $input['fingerprint']) && hash_equals($current['preview'], $input['preview']), 409, 'Conflict, provider or local record changed; obtain a new preview.');

        return $current;
    }

    public function apply(User $actor, NextcloudConnection $connection, NextcloudSyncConflict $conflict, array $input, string $leaseToken): CalendarEvent
    {
        $this->verify($actor, $connection, $conflict, $input);
        $state = $this->inspect($actor, $connection, $conflict, $input['resolution'], $input['event_id']);
        abort_unless(hash_equals($input['fingerprint'], $state['fingerprint']), 409, 'Conflict changed during reconciliation.');
        $lease = app(NextcloudCalendarLease::class);
        $lease->assertOwned($connection->id, $leaseToken);
        $lease->assertCalendarOwned($state['mapping']->calendar_id, $leaseToken);
        $event = $state['event'];
        $mapping = $state['mapping'];
        $resource = $state['resource'];
        $projection = app(SyncMappedNextcloudCalendars::class);
        $mutator = app(ApplyExternalCalendarEvent::class);
        if ($input['resolution'] === 'keep_local' && $state['local_hash'] !== $state['remote_hash']) {
            // Commit intent before HTTP. A timeout, worker death or 412 never causes a blind second PUT.
            $event = $mutator->handle($event, [
                'external_source' => 'nextcloud', 'external_calendar_id' => $mapping->remote_calendar_id,
                'external_event_id' => $resource['href'], 'external_uid' => $resource['event']['uid'], 'external_etag' => $resource['etag'],
                'sync_status' => 'pending_write', 'metadata' => [
                    'nextcloud_pending_write' => true, 'nextcloud_pending_connection_id' => $connection->id,
                    'nextcloud_pending_mapping_id' => $mapping->id,
                ],
            ]);
            CalendarApiAccess::actor($actor);
            abort_unless($actor->fresh()->can('calendar.update'), 403);
            CalendarApiAccess::mapping($actor->fresh(), $mapping->fresh());
            abort_unless(hash_equals(CalendarApiAccess::revision($connection), $state['connection_revision'])
                && $event->fresh()->getRawOriginal() === $event->getRawOriginal(), 409, 'Scope or local event changed before provider write.');
            $lease->assertOwned($connection->id, $leaseToken);
            app(NextcloudReadClient::class)->putCalendarEvent($connection->fresh(), $mapping->remote_calendar_id, $event);
            $resource = $projection->normalizeResource(app(NextcloudReadClient::class)->calendarEvent($connection, $mapping->remote_calendar_id, $resource['href']), $mapping);
            abort_unless(($resource['event']['uid'] ?? null) === $event->external_uid
                && ! empty($resource['etag']) && ($resource['event']['__write_safe'] ?? false)
                && $projection->remoteHash($resource['event']) === $projection->localHash($event), 409, 'Provider read-back differs; delivery intent retained.');
        }

        return DB::transaction(function () use ($actor, $connection, $conflict, $input, $event, $mapping, $resource, $projection, $mutator, $lease, $leaseToken) {
            $currentActor = CalendarApiAccess::actor($actor);
            abort_unless($currentActor->can('calendar.update'), 403);
            CalendarApiAccess::mapping($currentActor, $mapping->fresh());
            NextcloudConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $lease->assertOwned($connection->id, $leaseToken);
            $data = $projection->binding($connection, $mapping, $resource, $projection->remoteHash($resource['event']), true);
            if ($input['resolution'] === 'keep_remote') {
                $data = array_merge($projection->fields($resource['event'], $mapping), $data);
            }
            $result = $mutator->handle($event, $data);
            $conflict = NextcloudSyncConflict::lockForUpdate()->findOrFail($conflict->id);
            abort_unless($conflict->status === 'open', 409, 'Conflict was already handled.');
            $conflict->forceFill(['status' => 'resolved', 'resolution' => $input['resolution'], 'resolved_by' => $actor->id, 'resolved_at' => now()])->save();
            app(DomainApiAudit::class)->record($actor, 'calendar', 'calendar.api.nextcloud.conflict.applied', $result,
                ['conflict_open' => true], ['conflict_open' => false], ['request_fingerprint' => $input['fingerprint']]);

            return $result;
        });
    }

    private function inspect(User $actor, NextcloudConnection $connection, NextcloudSyncConflict $conflict, string $resolution, ?int $eventId): array
    {
        $actor = CalendarApiAccess::actor($actor);
        abort_unless($actor->can('calendar.update'), 403, 'Calendar update permission is required.');
        abort_unless(in_array($resolution, ['bind_existing', 'keep_local', 'keep_remote'], true), 422);
        $connection = $connection->fresh();
        $conflict = $conflict->fresh();
        abort_unless($connection->scope === 'global' && $connection->is_active && ($connection->settings['calendar_sync_enabled'] ?? false) === true, 409, 'Calendar synchronization is paused.');
        abort_unless((int) $conflict->connection_id === (int) $connection->id && $conflict->remote_object_type === 'calendar_event'
            && $conflict->conflictable_type === CalendarEvent::class && $conflict->status === 'open', 409, 'Conflict is not an open calendar event conflict.');
        $mapping = $connection->calendarMappings()->with(['calendar', 'user'])->findOrFail(data_get($conflict->local_snapshot, 'mapping_id'));
        CalendarApiAccess::mapping($actor, $mapping);
        $id = $eventId ?: $conflict->conflictable_id;
        abort_if($conflict->conflictable_id && (int) $id !== (int) $conflict->conflictable_id, 422, 'The selected event does not match the conflict.');
        $event = CalendarEvent::query()->findOrFail($id);
        abort_unless((int) $event->calendar_id === (int) $mapping->calendar_id
            && app(CalendarVisibility::class)->canManageCalendar($actor, $event->calendar)
            && app(CalendarVisibility::class)->canViewPrivateDetails($actor, $event), 403);
        ProjectWorkdayAbsence::assertCalendarEditable($event);
        abort_if($event->source === 'work_plan' || $event->series_id || $event->participants()->exists(), 422, 'Source-owned, recurring or participant-bearing events cannot be reconciled by this contract.');
        if (data_get($event->metadata, 'nextcloud_pending_write', false)) {
            abort_unless((int) data_get($event->metadata, 'nextcloud_pending_connection_id') === (int) $connection->id
                && (int) data_get($event->metadata, 'nextcloud_pending_mapping_id') === (int) $mapping->id, 409, 'Unknown delivery belongs to another provider scope.');
        }
        $client = app(NextcloudReadClient::class);
        $projection = app(SyncMappedNextcloudCalendars::class);
        try {
            $resource = $projection->normalizeResource($client->calendarEvent($connection, $mapping->remote_calendar_id, $conflict->remote_object_id), $mapping);
        } catch (\Throwable) {
            abort(503, 'Calendar provider resource cannot be verified; no change performed.');
        }
        $remote = $resource['event'];
        abort_unless(! empty($resource['etag']) && ! empty($remote['uid']) && ($remote['__write_safe'] ?? false)
            && ! ($remote['__series'] ?? false) && ! ($remote['__invalid'] ?? false)
            && isset($remote['dtstart']['value'], $remote['dtend']['value']) && $remote['dtend']['value']->gt($remote['dtstart']['value']), 422, 'Provider event is not safely supported; retained without change.');
        $uid = $remote['uid'];
        abort_if($event->external_source && ($event->external_source !== 'nextcloud'
            || $event->external_calendar_id !== $mapping->remote_calendar_id || $event->external_uid !== $uid
            || $event->external_event_id !== $resource['href']
            || (int) data_get($event->metadata, 'nextcloud_connection_id', $connection->id) !== (int) $connection->id), 409, 'Event is already bound to another provider identity.');
        abort_if(CalendarEvent::withTrashed()->whereKeyNot($event->id)->where('external_source', 'nextcloud')
            ->where('external_calendar_id', $mapping->remote_calendar_id)->where(fn ($q) => $q->where('external_uid', $uid)->orWhere('external_event_id', $resource['href']))->exists(), 409, 'Another local event claims this identity.');
        try {
            $inventory = $client->calendarEvents($connection, $mapping->remote_calendar_id, null, null, true);
        } catch (\Throwable) {
            abort(503, 'Calendar provider inventory cannot be verified; no change performed.');
        }
        abort_if(count($inventory) > 2000, 422, 'Provider collection exceeds interactive reconciliation limit.');
        abort_unless(count(array_filter($inventory, fn ($row) => ($row['event']['uid'] ?? null) === $uid)) === 1, 409, 'Provider UID is missing or duplicated.');
        $localHash = $projection->localHash($event);
        $remoteHash = $projection->remoteHash($remote);
        abort_if($resolution === 'bind_existing' && $localHash !== $remoteHash, 409, 'Binding requires equal supported content; choose an explicit content resolution.');
        abort_if($resolution === 'keep_remote' && $mapping->sync_direction === 'push_only', 422, 'Push-only mapping does not accept remote content.');
        if ($resolution === 'keep_local' && $localHash !== $remoteHash) {
            abort_unless($connection->canWrite() && $mapping->sync_direction !== 'pull_only', 403, 'Mapping does not permit provider writes.');
            abort_if(data_get($event->metadata, 'nextcloud_pending_write', false), 409, 'Unknown delivery must first be observed equal or explicitly reconciled to the remote content.');
        }
        $connectionRevision = CalendarApiAccess::revision($connection);
        $fingerprint = hash('sha256', json_encode([$connectionRevision, $conflict->getRawOriginal(),
            CalendarApiContract::revision($event), $resource['href'], $resource['etag'], $uid, $remoteHash]));

        return ['mapping' => $mapping, 'event' => $event, 'resource' => $resource, 'fingerprint' => $fingerprint,
            'local_hash' => $localHash, 'remote_hash' => $remoteHash, 'connection_revision' => $connectionRevision];
    }
}
