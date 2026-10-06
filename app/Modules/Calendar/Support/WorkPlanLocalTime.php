<?php

namespace App\Modules\Calendar\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class WorkPlanLocalTime
{
    /** Reject gaps and folds instead of silently moving a planned local clock time. */
    public static function parse(string $local, string $timezone, string $field): Carbon
    {
        $local = str_replace('T', ' ', $local);
        $naive = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, new DateTimeZone('UTC'));
        if (! $naive || $naive->format('Y-m-d H:i') !== $local) {
            throw ValidationException::withMessages([$field => 'Use a valid local date and time.']);
        }
        $zone = new DateTimeZone($timezone);
        $epoch = $naive->getTimestamp();
        $candidates = [];
        foreach ($zone->getTransitions($epoch - 172800, $epoch + 172800) as $transition) {
            $candidate = Carbon::createFromTimestampUTC($epoch - $transition['offset'])->timezone($timezone);
            if ($candidate->format('Y-m-d H:i') === $local) {
                $candidates[$candidate->timestamp] = $candidate;
            }
        }
        if (count($candidates) !== 1) {
            throw ValidationException::withMessages([$field => 'This local time is missing or repeated during a timezone clock change. Choose an unambiguous time.']);
        }

        return array_values($candidates)[0];
    }
}
