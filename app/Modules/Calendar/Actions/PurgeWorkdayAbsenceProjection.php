<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Workday\Models\WorkdayAbsence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurgeWorkdayAbsenceProjection
{
    /** Only the retention command calls this under the employee/source lock; generic Calendar editors stay forbidden. */
    public function handle(WorkdayAbsence $absence, CarbonImmutable $cutoff): void
    {
        if (! config('workday.retention_enabled') || DB::transactionLevel() === 0 || $absence->expires_at->gt($cutoff)) {
            throw new RuntimeException('workday_projection_purge_denied');
        }
        if (! $absence->calendar_event_id) {
            if (DB::table('calendar_event_links')->where('workday_absence_id', $absence->id)->exists()) {
                throw new RuntimeException('workday_projection_mismatch');
            }

            return;
        }
        $event = CalendarEvent::withoutGlobalScope('workday_retention')->withTrashed()->whereKey($absence->calendar_event_id)->lockForUpdate()->first();
        $calendar = $event ? DB::table('calendars')->where('id', $event->calendar_id)->lockForUpdate()->first() : null;
        $links = DB::table('calendar_event_links')->where('event_id', $absence->calendar_event_id)->lockForUpdate()->get();
        $owned = $event && $event->source === ProjectWorkdayAbsence::SOURCE && (int) $event->created_by === $absence->user_id
            && $calendar && $calendar->owner_type === (new User)->getMorphClass() && (int) $calendar->owner_id === $absence->user_id
            && ! $event->series_id && ! $event->external_source && ! $event->external_event_id
            && ! $event->participants()->exists()
            && ! DB::table('calendar_event_exceptions')->where('replacement_event_id', $event->id)->exists()
            && ! DB::table('booking_requests')->where('calendar_event_id', $event->id)->exists()
            && ! DB::table('sales_opportunities')->where('follow_up_calendar_event_id', $event->id)->exists()
            && $links->count() === 1 && $links->every(fn ($link) => (int) $link->workday_absence_id === $absence->id
                && $link->linkable_type === WorkdayAbsence::class && (int) $link->linkable_id === $absence->id && $link->relation === 'availability');
        if (! $owned) {
            throw new RuntimeException('workday_projection_mismatch');
        }
        // Clear the restrictive source pointer before physical deletion; the surrounding transaction owns rollback.
        DB::table('calendar_event_links')->where('event_id', $event->id)->delete();
        $absence->update(['calendar_event_id' => null]);
        DB::table('calendar_events')->where('id', $event->id)->where('source', ProjectWorkdayAbsence::SOURCE)->delete();
    }
}
