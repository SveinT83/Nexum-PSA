<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\WorkdayReminderNotification;
use App\Modules\Workday\Models\WorkdayReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurgeWorkdayReminderCopies
{
    public function forReminder(WorkdayReminder $reminder, CarbonImmutable $cutoff): void
    {
        $this->guard();
        if ($reminder->expires_at->gt($cutoff)) {
            throw new RuntimeException('workday_notification_purge_denied');
        }
        // The unchanged landing URL covers every earlier snooze generation, including pre-metadata notices.
        DB::table('notifications')->where('type', WorkdayReminderNotification::class)
            ->where('notifiable_type', (new User)->getMorphClass())->where('notifiable_id', $reminder->user_id)
            ->where(fn ($q) => $q->where('data->workday_reminder', $reminder->uuid)
                ->orWhere('data->url', route('tech.workday-reminders.open', $reminder->uuid, false)))->delete();
    }

    /** New copies carry a stable source UUID and original expiry, so a partial restore cannot orphan personal copies. */
    public function expiredOrOrphaned(CarbonImmutable $cutoff)
    {
        return DB::table('notifications as n')->where('n.type', WorkdayReminderNotification::class)
            ->whereNotNull('n.data->workday_reminder')->where(function ($query) use ($cutoff) {
                $query->where('n.data->workday_expires_at', '<=', $cutoff->utc()->format('Y-m-d\TH:i:s\Z'))
                    ->orWhereNotExists(function ($q) {
                        $q->selectRaw('1')->from('workday_reminders as r')->whereColumn('r.uuid', 'n.data->workday_reminder')
                            ->whereColumn('r.user_id', 'n.notifiable_id');
                    });
            });
    }

    public function purgeCopy(string $id, CarbonImmutable $cutoff): void
    {
        $this->guard();
        $this->expiredOrOrphaned($cutoff)->where('n.id', $id)->delete();
    }

    /** Legacy notices with no surviving source cannot be assigned a guessed retention deadline. */
    public function untracked(): int
    {
        // A legacy URL with a surviving owner/source is sufficient; only unknown provenance blocks restore.
        [$prefix, $suffix] = explode('__retention_uuid__', route('tech.workday-reminders.open', '__retention_uuid__', false));
        $url = DB::connection()->getQueryGrammar()->wrap('n.data->url');
        $joined = DB::connection()->getDriverName() === 'sqlite' ? '(? || r.uuid || ?)' : 'CONCAT(?, r.uuid, ?)';

        return DB::table('notifications as n')->where('n.type', WorkdayReminderNotification::class)
            ->whereNull('n.data->workday_reminder')->whereNotExists(function ($q) use ($prefix, $suffix, $url, $joined) {
                $q->selectRaw('1')->from('workday_reminders as r')->whereColumn('r.user_id', 'n.notifiable_id')
                    ->where('n.notifiable_type', (new User)->getMorphClass())->whereRaw($url.' = '.$joined, [$prefix, $suffix]);
            })->count();
    }

    private function guard(): void
    {
        if (! config('workday.retention_enabled') || DB::transactionLevel() === 0) {
            throw new RuntimeException('workday_notification_purge_denied');
        }
    }
}
