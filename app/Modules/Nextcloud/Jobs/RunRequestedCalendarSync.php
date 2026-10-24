<?php

namespace App\Modules\Nextcloud\Jobs;

use App\Models\Core\User;
use App\Modules\Nextcloud\Actions\CalendarApiAccess;
use App\Modules\Nextcloud\Actions\RunNextcloudCalendarSync;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** A manual API job has frozen explicit targets; it never invokes the full connection sync. */
class RunRequestedCalendarSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $connectionId, public string $token, public int $logId,
        public int $actorId, public array $mappingIds, public string $revision) {}

    public function handle(RunNextcloudCalendarSync $runner): void
    {
        $guard = function () {
            $actor = CalendarApiAccess::actor(User::findOrFail($this->actorId));
            $connection = NextcloudConnection::findOrFail($this->connectionId);
            abort_unless(hash_equals(CalendarApiAccess::revision($connection), $this->revision), 409, 'Reviewed Calendar scope changed.');
            $mappings = $connection->calendarMappings()->whereIn('id', $this->mappingIds)->with(['calendar', 'user'])->get();
            abort_unless($mappings->count() === count($this->mappingIds), 409);
            foreach ($mappings as $mapping) {
                CalendarApiAccess::mapping($actor, $mapping);
            }
        };
        $runner->handle($this->connectionId, $this->token, $this->logId, false, $this->mappingIds, $guard);
    }
}
