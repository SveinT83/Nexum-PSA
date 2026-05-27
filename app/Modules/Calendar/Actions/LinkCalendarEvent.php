<?php

namespace App\Modules\Calendar\Actions;

use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventLink;
use Illuminate\Database\Eloquent\Model;

class LinkCalendarEvent
{
    public function handle(CalendarEvent $event, Model $record, string $relation = 'scheduled_for', array $metadata = []): CalendarEventLink
    {
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
