<?php

namespace App\Modules\Nextcloud\Jobs;

use App\Models\Core\User;
use App\Modules\Nextcloud\Actions\NextcloudCalendarLease;
use App\Modules\Nextcloud\Actions\ReconcileNextcloudCalendarConflict;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncConflict;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** One attempt only. A failure retains conflict and any committed provider delivery intent. */
class ReconcileRequestedCalendarConflict implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $connectionId, public int $conflictId, public int $actorId,
        public string $token, public int $logId, public array $input) {}

    public function handle(ReconcileNextcloudCalendarConflict $resolver): void
    {
        $connection = NextcloudConnection::find($this->connectionId);
        if (! $connection || NextcloudSyncLog::whereKey($this->logId)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()]) !== 1) {
            return;
        }
        $success = false;
        try {
            $resolver->apply(User::findOrFail($this->actorId), $connection, NextcloudSyncConflict::findOrFail($this->conflictId), $this->input, $this->token);
            $success = true;
            NextcloudSyncLog::whereKey($this->logId)->update(['status' => 'success', 'records_changed' => 1, 'finished_at' => now(), 'message' => 'Calendar conflict reconciled and read back.']);
        } catch (Throwable) {
            NextcloudSyncLog::whereKey($this->logId)->update(['status' => 'failed', 'records_failed' => 1, 'finished_at' => now(), 'message' => 'Calendar reconciliation failed or changed; conflict and unknown delivery evidence retained.']);
        } finally {
            app(NextcloudCalendarLease::class)->finish($connection, $this->token, $success);
        }
    }
}
