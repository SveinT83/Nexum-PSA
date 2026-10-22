<?php

namespace App\Modules\Nextcloud\Jobs;

use App\Modules\Nextcloud\Actions\RunNextcloudCalendarSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncNextcloudCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $connectionId, public string $token, public int $logId) {}

    public function handle(RunNextcloudCalendarSync $sync): void
    {
        $sync->handle($this->connectionId, $this->token, $this->logId, true);
    }
}
