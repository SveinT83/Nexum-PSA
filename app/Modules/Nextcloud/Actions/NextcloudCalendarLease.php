<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class NextcloudCalendarLease
{
    // Workers time out in 300 seconds; their lease cannot expire during an active worker.
    public const LEASE_SECONDS = 600;

    public function claim(NextcloudConnection $connection, bool $scheduled, ?array $requestedMappingIds = null): ?array
    {
        DB::table('nextcloud_calendar_sync_states')->insertOrIgnore([
            'connection_id' => $connection->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::transaction(function () use ($connection, $scheduled, $requestedMappingIds) {
            $state = DB::table('nextcloud_calendar_sync_states')->where('connection_id', $connection->id)->lockForUpdate()->first();
            if ($state->lease_until && now()->lt($state->lease_until)) {
                return null;
            }
            if ($scheduled && $state->next_due_at && now()->lt($state->next_due_at)) {
                return null;
            }
            $calendarIds = $connection->calendarMappings()->where('is_active', true)->whereNotNull('calendar_id')
                ->when($scheduled || $requestedMappingIds !== null, fn ($q) => $q->whereIn('id', $scheduled ? DispatchDueNextcloudCalendars::mappingIds($connection) : $requestedMappingIds))
                ->orderBy('calendar_id')->pluck('calendar_id')->unique()->values()->all();
            foreach ($calendarIds as $calendarId) {
                DB::table('nextcloud_calendar_sync_locks')->insertOrIgnore(['calendar_id' => $calendarId]);
                $lock = DB::table('nextcloud_calendar_sync_locks')->where('calendar_id', $calendarId)->lockForUpdate()->first();
                if ($lock->lease_until && now()->lt($lock->lease_until)) {
                    return null;
                }
            }
            if ($state->log_id) {
                NextcloudSyncLog::whereKey($state->log_id)->whereIn('status', ['queued', 'running'])->update([
                    'status' => 'failed', 'finished_at' => now(), 'message' => 'Expired calendar sync lease; provider state will be reconciled before any retry.',
                ]);
            }
            $token = (string) Str::uuid();
            DB::table('nextcloud_calendar_sync_locks')->whereIn('calendar_id', $calendarIds)->update([
                'token' => $token, 'lease_until' => now()->addSeconds(self::LEASE_SECONDS),
            ]);
            $log = NextcloudSyncLog::create([
                'connection_id' => $connection->id, 'operation' => $scheduled ? 'scheduled_calendar_sync' : 'manual_calendar_sync',
                'status' => 'queued', 'credential_source' => 'service',
                'context' => ['calendar_only' => true], 'message' => 'Calendar sync claimed.',
            ]);
            DB::table('nextcloud_calendar_sync_states')->where('connection_id', $connection->id)->update([
                'token' => $token, 'log_id' => $log->id, 'lease_until' => now()->addSeconds(self::LEASE_SECONDS),
                'updated_at' => now(),
            ]);

            return ['token' => $token, 'log_id' => $log->id];
        });
    }

    public function assertOwned(int $connectionId, string $token): void
    {
        $owned = DB::table('nextcloud_calendar_sync_states')->where('connection_id', $connectionId)
            ->where('token', $token)->where('lease_until', '>', now())->exists();
        if (! $owned) {
            throw new RuntimeException('Calendar sync lease is no longer owned.');
        }
    }

    public function assertCalendarOwned(int $calendarId, string $token): void
    {
        if (! DB::table('nextcloud_calendar_sync_locks')->where('calendar_id', $calendarId)
            ->where('token', $token)->where('lease_until', '>', now())->exists()) {
            throw new RuntimeException('Calendar lease is no longer owned.');
        }
    }

    public function finish(NextcloudConnection $connection, string $token, bool $completed): void
    {
        DB::table('nextcloud_calendar_sync_locks')->where('token', $token)->update(['token' => null, 'lease_until' => null]);
        DB::table('nextcloud_calendar_sync_states')->where('connection_id', $connection->id)->where('token', $token)->update([
            'token' => null, 'lease_until' => null,
            'next_due_at' => now()->addMinutes(max(1, (int) $connection->sync_interval_minutes)),
            'last_completed_at' => $completed ? now() : null, 'updated_at' => now(),
        ]);
    }
}
