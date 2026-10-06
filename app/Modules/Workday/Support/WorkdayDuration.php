<?php

namespace App\Modules\Workday\Support;

use Illuminate\Support\Facades\Validator;

/** Tripletex preserves hundredths of an hour: one unit is exactly 36 seconds. */
final class WorkdayDuration
{
    public function snapshot(string $date, array $input): array
    {
        Validator::make(['work_date' => $date] + $input, [
            'work_date' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['required', 'timezone:all'],
            'description' => ['required', 'string', 'max:2000'],
            'durations' => ['present', 'array', 'max:100'],
            'durations.*' => ['array:activity_id,project_id,hours,comment'],
            'durations.*.activity_id' => ['required', 'integer', 'min:1'],
            'durations.*.project_id' => ['nullable', 'integer', 'min:1'],
            'durations.*.hours' => ['required', 'numeric', 'min:0', 'max:24', 'decimal:0,2'],
            'durations.*.comment' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $rows = [];
        foreach ($input['durations'] as $row) {
            $units = (int) round((float) $row['hours'] * 100);
            if (! $units) {
                continue; // An explicit zero is the employee's versioned removal.
            }
            $key = (int) $row['activity_id'].':'.(int) ($row['project_id'] ?? 0);
            abort_if(isset($rows[$key]), 422, 'Use one duration per activity and project.');
            $rows[$key] = ['activity_id' => (int) $row['activity_id'],
                'project_id' => empty($row['project_id']) ? null : (int) $row['project_id'],
                'units' => $units, 'comment' => trim($row['comment'] ?? '')];
        }
        ksort($rows);
        $minutes = round(array_sum(array_column($rows, 'units')) * 0.6, 1);
        abort_if($minutes > 1440, 422, 'A work date cannot contain more than 24 hours.');

        return ['description' => trim($input['description']), 'intervals' => [], 'breaks' => [],
            'durations' => array_values($rows), 'gross_minutes' => $minutes, 'actual_minutes' => $minutes,
            'excluded_break_minutes' => 0, 'included_break_minutes' => 0,
            'allocations' => [], 'allocated_minutes' => 0, 'unallocated_minutes' => $minutes];
    }
}
