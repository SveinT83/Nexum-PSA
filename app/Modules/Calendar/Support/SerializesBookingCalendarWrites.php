<?php

namespace App\Modules\Calendar\Support;

use Illuminate\Database\Eloquent\Builder;

/** Eloquent event/series/exception writes join the same database transaction and mutex. */
trait SerializesBookingCalendarWrites
{
    protected function performInsert(Builder $query)
    {
        return CalendarBookingWriteBoundary::run(fn () => parent::performInsert($query));
    }

    protected function performUpdate(Builder $query)
    {
        return CalendarBookingWriteBoundary::run(fn () => parent::performUpdate($query));
    }
}
