<?php

namespace App\Modules\Marketing\Support;

use Illuminate\Support\Carbon;

/** Shared web/API validation and normalization boundary. */
trait MarketingScheduleInput
{
    private function normalizeSchedulePayload(array $data): array
    {
        $frequency = $data['schedule_frequency'] ?? null;

        if (! $frequency) {
            return [
                'starts_at' => $this->dateTimeFromLegacyPayload($data),
                'sequence_interval_value' => $data['sequence_interval_value'] ?? 1,
                'sequence_interval_unit' => $data['sequence_interval_unit'] ?? 'days',
            ];
        }

        $startsAt = $this->startsAtFromScheduleFields($data);

        return match ($frequency) {
            'daily' => [
                'starts_at' => $startsAt,
                'sequence_interval_value' => 1,
                'sequence_interval_unit' => 'days',
            ],
            'weekly' => [
                'starts_at' => $this->weeklyStart($startsAt, (int) ($data['send_weekday'] ?? 5)),
                'sequence_interval_value' => 1,
                'sequence_interval_unit' => 'weeks',
            ],
            'monthly' => [
                'starts_at' => $this->monthlyStart($startsAt, (int) ($data['month_day'] ?? 1)),
                'sequence_interval_value' => 1,
                'sequence_interval_unit' => 'months',
            ],
            default => [
                'starts_at' => $startsAt,
                'sequence_interval_value' => $data['custom_interval_value'] ?? $data['sequence_interval_value'] ?? 1,
                'sequence_interval_unit' => $data['custom_interval_unit'] ?? $data['sequence_interval_unit'] ?? 'days',
            ],
        };
    }

    private function startsAtFromScheduleFields(array $data): ?Carbon
    {
        if (! empty($data['first_send_date'])) {
            $time = $data['send_time'] ?? '12:00';

            return Carbon::parse($data['first_send_date'].' '.$time)->seconds(0);
        }

        return $this->dateTimeFromLegacyPayload($data);
    }

    private function dateTimeFromLegacyPayload(array $data): ?Carbon
    {
        if (empty($data['starts_at'])) {
            return null;
        }

        return Carbon::parse($data['starts_at'])->seconds(0);
    }

    private function weeklyStart(?Carbon $startsAt, int $weekday): ?Carbon
    {
        if (! $startsAt) {
            return null;
        }

        $weekday = max(1, min(7, $weekday));
        $daysToAdd = ($weekday - (int) $startsAt->format('N') + 7) % 7;

        return $startsAt->copy()->addDays($daysToAdd);
    }

    private function monthlyStart(?Carbon $startsAt, int $monthDay): ?Carbon
    {
        if (! $startsAt) {
            return null;
        }

        $monthDay = max(1, min(31, $monthDay));
        $candidate = $this->dateOnMonthDay($startsAt, $monthDay);

        if ($candidate->lt($startsAt)) {
            $candidate = $this->dateOnMonthDay($startsAt->copy()->addMonthNoOverflow(), $monthDay);
        }

        return $candidate;
    }

    private function dateOnMonthDay(Carbon $date, int $monthDay): Carbon
    {
        $month = $date->copy()->day(1);
        $safeDay = min($monthDay, $month->daysInMonth);

        return $month->day($safeDay)->setTime((int) $date->format('H'), (int) $date->format('i'));
    }
}
