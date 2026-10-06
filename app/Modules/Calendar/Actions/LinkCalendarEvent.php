<?php

namespace App\Modules\Calendar\Actions;

use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventLink;
use Illuminate\Database\Eloquent\Model;

class LinkCalendarEvent
{
    public function handle(CalendarEvent $event, Model $record, string $relation = 'scheduled_for', array $metadata = []): CalendarEventLink
    {
        if ($record instanceof \App\Modules\Workday\Models\WorkdayAbsence) {
            abort_unless(\Illuminate\Support\Facades\DB::transactionLevel() > 0
                && $event->source === ProjectWorkdayAbsence::SOURCE
                && (int) $event->created_by === $record->user_id
                && (int) $record->calendar_event_id === (int) $event->id, 409, 'Invalid Workday projection link.');
            $existing = CalendarEventLink::query()->where('workday_absence_id', $record->id)->first();
            abort_if($existing && (int) $existing->event_id !== (int) $event->id, 409, 'This absence already has a Calendar projection.');

            return CalendarEventLink::query()->updateOrCreate(['workday_absence_id' => $record->id],
                ['event_id' => $event->id, 'linkable_type' => $record::class, 'linkable_id' => $record->id,
                    'relation' => 'availability', 'metadata' => null]);
        }
        ProjectWorkdayAbsence::assertCalendarEditable($event);

        return CalendarEventLink::query()->updateOrCreate(
            [
                'event_id' => $event->id,
                'linkable_type' => $record::class,
                'linkable_id' => $record->getKey(),
                'relation' => $relation,
            ],
            ['metadata' => $metadata ?: null]
        );
    }
}
