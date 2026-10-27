<?php

namespace App\Modules\Calendar\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Serialize booking checks with Calendar model writers without forbidding ordinary overlaps. */
class CalendarBookingWriteBoundary
{
    public static function run(callable $write): mixed
    {
        // Compatibility during an ordered code/migration rollout; Booking API effects stay off.
        if (! Schema::hasTable('calendar_booking_write_mutex')) {
            return $write();
        }

        return DB::transaction(function () use ($write) {
            DB::table('calendar_booking_write_mutex')->where('id', 1)->lockForUpdate()->first();

            return $write();
        });
    }
}
