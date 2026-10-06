<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Workday\Models\WorkdayAbsence;
use App\Modules\Workday\Support\WorkdayAccess;
use Illuminate\Support\Facades\DB;

class ProjectWorkdayAbsence
{
    public const SOURCE = 'workday_absence';

    /** Only an authorized persisted own source may publish this neutral, non-exportable projection. */
    public function handle(WorkdayAbsence $absence, User $worker): CalendarEvent
    {
        app(WorkdayAccess::class)->authorize($worker, 'absence_manage_own');
        abort_unless(DB::transactionLevel() > 0, 409, 'Absence and Calendar must be saved in one transaction.');
        $absence = $absence->fresh();
        abort_unless($absence && $absence->user_id === (int) $worker->id && $absence->expires_at->isFuture(), 404);
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($worker, seedWorkingWeek: false)->refresh();
        abort_unless($calendar->is_active, 409, 'Your personal Calendar is inactive.');
        $data = ['calendar_id' => $calendar->id, 'title' => 'Unavailable', 'description' => null,
            'location' => null, 'meeting_url' => null, 'starts_at' => $absence->starts_at,
            'ends_at' => $absence->ends_at, 'timezone' => $absence->timezone,
            'all_day' => $absence->mode === 'full_day', 'status' => $absence->status === 'cancelled' ? 'cancelled' : 'confirmed',
            'transparency' => 'busy', 'visibility' => 'default', 'source' => self::SOURCE, 'metadata' => null,
            'updated_by' => $worker->id];
        if ($absence->calendar_event_id) {
            $event = CalendarEvent::query()->lockForUpdate()->findOrFail($absence->calendar_event_id);
            abort_unless($event->source === self::SOURCE && (int) $event->created_by === $absence->user_id
                && $event->calendar_id === $calendar->id && ! $event->series_id && ! $event->external_source
                && ! $event->participants()->exists(), 409, 'The Calendar projection needs repair before this absence can change.');
            // Deliberately narrow write: generic model updates/deletes are forbidden for owned blocks.
            DB::table('calendar_events')->where('id', $event->id)->update($data + ['updated_at' => now()]);
            $event->refresh();
        } else {
            $event = app(StoreCalendarEvent::class)->handle($data, $worker);
            $absence->update(['calendar_event_id' => $event->id]);
        }
        app(LinkCalendarEvent::class)->handle($event, $absence, 'availability');

        return $event->fresh();
    }

    public static function assertCalendarEditable(CalendarEvent $event): void
    {
        abort_if($event->source === self::SOURCE || $event->getRawOriginal('source') === self::SOURCE, 409,
            'This unavailable block is maintained by Workday. Its owner must edit or cancel it in My absences.');
    }
}
