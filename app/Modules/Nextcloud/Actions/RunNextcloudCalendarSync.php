<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunNextcloudCalendarSync
{
    public function manual(NextcloudConnection $connection): array
    {
        if (! $connection->is_active || ! ($connection->settings['calendar_sync_enabled'] ?? false)) {
            throw new \RuntimeException('Calendar synchronization is paused.');
        }
        $claim = app(NextcloudCalendarLease::class)->claim($connection, false);
        if (! $claim) {
            throw new \RuntimeException('Calendar synchronization is already queued or running.');
        }

        return $this->handle($connection->id, $claim['token'], $claim['log_id'], false);
    }

    public function handle(int $connectionId, string $token, int $logId, bool $scheduled, ?array $requestedMappingIds = null, ?callable $authorizationGuard = null): array
    {
        $summary = ['seen' => 0, 'changed' => 0, 'imported' => 0, 'pushed' => 0, 'conflicts' => 0, 'skipped' => 0, 'failed' => 0];
        $connection = NextcloudConnection::find($connectionId);
        if (! $connection) {
            return $summary;
        }
        $lease = app(NextcloudCalendarLease::class);
        // A duplicate queue delivery must never enter an already-running attempt.
        $state = DB::table('nextcloud_calendar_sync_states')->where('connection_id', $connectionId)
            ->where('token', $token)->where('log_id', $logId)->where('lease_until', '>', now())->first();
        if (! $state || NextcloudSyncLog::whereKey($logId)->where('status', 'queued')->update([
            'status' => 'running', 'started_at' => now(),
        ]) !== 1) {
            return $summary;
        }
        try {
            $lease->assertOwned($connectionId, $token);
            if (! $connection->is_active || ! ($connection->settings['calendar_sync_enabled'] ?? false)
                || ($scheduled && ! DispatchDueNextcloudCalendars::enabled($connection))) {
                NextcloudSyncLog::whereKey($logId)->update([
                    'status' => 'cancelled', 'finished_at' => now(), 'message' => 'Calendar synchronization paused before execution.',
                ]);

                return $summary;
            }
            $connection->forceFill(['last_sync_requested_at' => now()])->save();
            $ids = $scheduled ? DispatchDueNextcloudCalendars::mappingIds($connection) : $requestedMappingIds;
            $summary = app(SyncMappedNextcloudCalendars::class)->handle($connection, $ids, $token, $scheduled, $authorizationGuard);
            $success = $summary['failed'] === 0 && $summary['conflicts'] === 0 && $summary['skipped'] === 0;
            NextcloudSyncLog::whereKey($logId)->update([
                'status' => $success ? 'success' : 'partial', 'finished_at' => now(),
                'records_seen' => $summary['seen'], 'records_changed' => $summary['changed'],
                'records_failed' => $summary['failed'],
                'context' => ['calendar_only' => true, 'mapping_ids' => $ids, 'summary' => $summary],
                'message' => $success ? 'Calendar synchronization completed.' : 'Calendar synchronization requires review; inspect conflicts and skipped resources.',
            ]);
            if ($success) {
                $connection->forceFill(['last_successful_sync_at' => now()])->save();
            }

            return $summary;
        } catch (Throwable) {
            $summary['failed']++;
            NextcloudSyncLog::whereKey($logId)->update([
                'status' => 'failed', 'finished_at' => now(), 'records_failed' => $summary['failed'],
                'message' => 'Calendar synchronization failed; provider state is reconciled before another write.',
            ]);

            return $summary;
        } finally {
            $lease->finish($connection, $token, $summary['failed'] === 0);
        }
    }
}
