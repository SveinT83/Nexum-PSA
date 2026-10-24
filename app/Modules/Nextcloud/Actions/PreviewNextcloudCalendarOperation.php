<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use Illuminate\Support\Carbon;

/** Explicit POST preflight reads the provider; it never starts sync or changes activation. */
class PreviewNextcloudCalendarOperation
{
    public function build(User $actor, NextcloudConnection $connection, string $operation, array $choices, ?string $expires = null): array
    {
        CalendarApiAccess::actor($actor);
        abort_unless($connection->scope === 'global', 422, 'Only the existing global calendar integration is supported.');
        $targets = [];
        if (in_array($operation, ['activation', 'sync'], true) && ($operation === 'sync' || ($choices['enabled'] ?? false))) {
            $ids = $choices['mapping_ids'] ?? [];
            abort_if($ids === [] || count($ids) > 10, 422, 'Select one to ten explicit calendar mappings.');
            $mappings = $connection->calendarMappings()->whereIn('id', $ids)->with(['calendar', 'user'])->get();
            abort_unless($mappings->count() === count(array_unique($ids)), 422, 'Mapping selection is incomplete.');
            abort_if($mappings->groupBy('calendar_id')->contains(fn ($group) => $group->count() > 1), 422, 'Select one remote destination for each local calendar.');
            foreach ($mappings as $mapping) {
                CalendarApiAccess::mapping($actor, $mapping);
                $targets[] = $this->inventory($connection, $mapping->remote_calendar_id, $mapping->calendar_id)
                    + ['mapping_id' => $mapping->id, 'calendar_id' => $mapping->calendar_id, 'user_id' => $mapping->user_id,
                        'direction' => $mapping->sync_direction, 'remote_calendar_id' => $mapping->remote_calendar_id];
            }
            abort_unless($connection->is_active && ($connection->settings['calendar_sync_enabled'] ?? false) === true, 409, 'Calendar synchronization is paused.');
        } elseif ($operation === 'mapping') {
            if (! empty($choices['calendar_id'])) {
                $calendar = \App\Modules\Calendar\Models\Calendar::findOrFail($choices['calendar_id']);
                abort_unless(app(\App\Modules\Calendar\Services\CalendarVisibility::class)->canManageCalendar($actor, $calendar), 403);
            }
            $targets[] = $this->inventory($connection, $choices['remote_calendar_id'], $choices['calendar_id'] ?? null);
        } elseif ($operation === 'remove_mapping') {
            abort_unless($connection->calendarMappings()->whereKey($choices['mapping_id'])->exists(), 404);
        }
        $revision = CalendarApiAccess::revision($connection);
        $fingerprint = hash('sha256', json_encode([$revision, $targets]));
        $expires ??= now()->addMinutes(10)->toIso8601String();
        abort_if(Carbon::parse($expires)->isPast() || Carbon::parse($expires)->gt(now()->addMinutes(11)), 409, 'Preview expired.');
        ksort($choices);
        $token = hash_hmac('sha256', json_encode([$actor->id, $connection->id, $operation, $choices, $fingerprint, $expires]), config('app.key'));

        return ['connection_id' => $connection->id, 'operation' => $operation, 'revision' => $revision,
            'fingerprint' => $fingerprint, 'expires_at' => $expires, 'preview' => $token, 'targets' => $targets];
    }

    public function verify(User $actor, NextcloudConnection $connection, string $operation, array $choices, array $proof): array
    {
        $current = $this->build($actor, $connection, $operation, $choices, $proof['expires_at']);
        abort_unless(hash_equals($current['preview'], $proof['preview']) && hash_equals($current['fingerprint'], $proof['fingerprint']), 409, 'Provider or scope changed; obtain a new preview.');

        return $current;
    }

    public function inventory(NextcloudConnection $connection, string $remote, ?int $calendarId): array
    {
        try {
            $resources = app(NextcloudReadClient::class)->calendarEvents($connection, $remote, null, null, true);
        } catch (\Throwable) {
            abort(503, 'Calendar provider preflight is unavailable; no change performed.');
        }
        abort_if(count($resources) > 2000, 422, 'Collection exceeds the safe interactive preflight limit; no write was performed.');
        $uids = [];
        $unsupported = 0;
        $duplicates = 0;
        $identity = [];
        foreach ($resources as $resource) {
            $event = $resource['event'];
            $uid = $event['uid'] ?? '';
            $uids[$uid] = ($uids[$uid] ?? 0) + 1;
            $unsupported += (int) (! ($event['__write_safe'] ?? false) || ($event['__series'] ?? false) || ($event['__invalid'] ?? false));
            $identity[] = [$resource['href'], $resource['etag'] ?? null, hash('sha256', $resource['calendar_data'] ?? '')];
            if ($calendarId && isset($event['dtstart']['value'], $event['dtend']['value'])) {
                $duplicates += CalendarEvent::withTrashed()->where('calendar_id', $calendarId)->whereNull('external_source')
                    ->where('title', $event['summary'] ?? '')->where('starts_at', $event['dtstart']['value']->copy()->utc())
                    ->where('ends_at', $event['dtend']['value']->copy()->utc())->count();
            }
        }
        sort($identity);

        return ['resource_count' => count($resources), 'unsupported_count' => $unsupported,
            'duplicate_uid_count' => count(array_filter($uids, fn ($count) => $count > 1)), 'local_duplicate_candidates' => $duplicates,
            'inventory_fingerprint' => hash('sha256', json_encode($identity)), 'complete' => true];
    }
}
