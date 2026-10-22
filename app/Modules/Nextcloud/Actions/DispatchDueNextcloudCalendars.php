<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Jobs\SyncNextcloudCalendar;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DispatchDueNextcloudCalendars
{
    public static function enabled(NextcloudConnection $connection): bool
    {
        return $connection->is_active && $connection->scope === 'global'
            && ($connection->settings['calendar_sync_enabled'] ?? false) === true
            && ($connection->settings['automatic_calendar_sync_enabled'] ?? false) === true
            && self::mappingIds($connection) !== []
            && ($connection->settings['automatic_calendar_target_fingerprint'] ?? null) === self::fingerprint($connection);
    }

    /**
     * Reviewed IDs alone are insufficient when an admin later retargets a connection
     * or mapping. Activation is bound to the exact non-secret provider/mapping scope.
     */
    public static function fingerprint(NextcloudConnection $connection): string
    {
        $mappings = $connection->calendarMappings()->whereIn('id', self::mappingIds($connection))
            ->orderBy('id')->get(['id', 'calendar_id', 'user_id', 'remote_calendar_id', 'sync_direction', 'is_active'])
            ->map(fn ($m) => [(int) $m->id, (int) $m->calendar_id, (int) $m->user_id,
                $m->remote_calendar_id, $m->sync_direction, (bool) $m->is_active])->all();

        return hash('sha256', json_encode([
            (int) $connection->id, $connection->scope, $connection->base_url,
            $connection->service_username, $mappings,
        ]));
    }

    public static function mappingIds(NextcloudConnection $connection): array
    {
        $ids = $connection->settings['automatic_calendar_mapping_ids'] ?? [];
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(
            $ids,
            fn ($id) => is_int($id) && $id > 0,
        ));
    }

    public function handle(): int
    {
        if (! Schema::hasTable('nextcloud_calendar_sync_states')) {
            return 0;
        }
        $started = 0;
        foreach (NextcloudConnection::where('is_active', true)->lazyById(50) as $connection) {
            if (! self::enabled($connection)
                || ! $connection->calendarMappings()->whereIn('id', self::mappingIds($connection))
                    ->where('is_active', true)->whereNotNull('calendar_id')->exists()) {
                continue;
            }
            $claim = app(NextcloudCalendarLease::class)->claim($connection, true);
            if (! $claim) {
                continue;
            }
            try {
                SyncNextcloudCalendar::dispatch($connection->id, $claim['token'], $claim['log_id']);
                $started++;
            } catch (Throwable) {
                NextcloudSyncLog::whereKey($claim['log_id'])->update([
                    'status' => 'failed', 'finished_at' => now(), 'message' => 'Calendar job dispatch failed.',
                ]);
                app(NextcloudCalendarLease::class)->finish($connection, $claim['token'], false);
            }
        }

        return $started;
    }
}
