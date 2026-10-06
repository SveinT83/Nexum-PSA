<?php

namespace App\Modules\Integration\Services\Tripletex;

use App\Models\System\Integrations\Integration;
use App\Modules\DataExchange\Models\TripletexWorkdaySyncState;
use App\Modules\Workday\Models\Workday;

final class TripletexTimeMapping
{
    public function forUser(int $userId): ?array
    {
        $connection = Integration::where('type', 'tripletex')->first();
        $mapping = $connection?->config['time_mappings'][(string) $userId] ?? null;

        return $mapping ? $mapping + ['connection_id' => $connection->id, 'enabled' => $connection->status === 'active',
            'activities' => $connection->config['time_catalog']['activities'] ?? [],
            'projects' => $connection->config['time_catalog']['projects'] ?? []] : null;
    }

    /** Save and its durable pending marker share the caller's database transaction. */
    public function pending(Workday $day): void
    {
        $mapping = $this->forUser($day->user_id);
        if (! $mapping || $day->work_date < $mapping['start_date']) {
            return;
        }
        TripletexWorkdaySyncState::firstOrCreate(
            ['connection_id' => $mapping['connection_id'], 'user_id' => $day->user_id, 'work_date' => $day->work_date],
            ['expires_at' => $day->expires_at]
        )->update(['status' => 'pending']);
    }

    public function normalizeClock(int $userId, string $date, string $timezone, array $snapshot): array
    {
        $mapping = $this->forUser($userId);
        if (! $mapping || array_key_exists('durations', $snapshot) || ! $snapshot['actual_minutes']) {
            return $snapshot;
        }
        foreach ($snapshot['intervals'] as $interval) {
            $end = \Carbon\CarbonImmutable::parse($interval['end'])->setTimezone($timezone);
            abort_if($end->toDateString() > $date && ! $end->isStartOfDay(), 422,
                'Split overnight work at midnight before synchronizing it with Tripletex.');
        }
        $units = (int) round($snapshot['actual_minutes'] * 100 / 60);
        $minutes = round($units * 0.6, 1);
        if (abs($minutes - $snapshot['actual_minutes']) < 0.000001) {
            return $snapshot;
        }
        abort_if(count($snapshot['allocations'] ?? []) > 0, 409,
            'This duration needs Tripletex rounding. Resolve source allocations before changing its precision.');
        // Keep the submitted clocks as provenance; only the rounded duration counts as effective time.
        $snapshot['original_intervals'] = $snapshot['intervals'];
        $snapshot['original_breaks'] = $snapshot['breaks'];
        $snapshot['intervals'] = $snapshot['breaks'] = [];
        $snapshot['durations'] = [['activity_id' => (int) $mapping['activity_id'], 'project_id' => null,
            'units' => $units, 'comment' => $snapshot['description']]];
        $snapshot['actual_minutes'] = $snapshot['gross_minutes'] = $snapshot['unallocated_minutes'] = $minutes;
        $snapshot['excluded_break_minutes'] = $snapshot['included_break_minutes'] = 0;

        return $snapshot;
    }

    public function validateRows(int $userId, array $snapshot): void
    {
        $mapping = $this->forUser($userId);
        abort_unless($mapping, 422, 'Configure an employee and activity mapping before using duration entries.');
        foreach ($snapshot['durations'] ?? [] as $row) {
            abort_unless(in_array($row['activity_id'], array_column($mapping['activities'], 'id'), true), 422, 'Activity is not mapped.');
            abort_if($row['project_id'] !== null && ! in_array($row['project_id'], array_column($mapping['projects'], 'id'), true), 422, 'Project is not mapped.');
        }
    }
}
