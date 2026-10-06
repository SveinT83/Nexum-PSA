<?php

namespace App\Console\Commands;

use App\Models\System\Integrations\Integration;
use App\Modules\DataExchange\Models\TripletexWorkdaySyncState;
use App\Modules\DataExchange\Services\SyncTripletexWorkdays;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Laravel\Telescope\Telescope;

class SyncTripletexTime extends Command
{
    protected $signature = 'tripletex:sync-time';

    protected $description = 'Reconcile mapped Workday time with Tripletex inside the retained activation window.';

    public function handle(SyncTripletexWorkdays $sync): int
    {
        // Telescope is a development dependency and is absent from production installs.
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        if (! config('tripletex.enabled') || ! config('tripletex.writes_enabled')) {
            return self::SUCCESS;
        }
        foreach (Integration::where('type', 'tripletex')->where('status', 'active')->get() as $connection) {
            foreach ($connection->config['time_mappings'] ?? [] as $userId => $mapping) {
                $today = CarbonImmutable::now($mapping['timezone'])->startOfDay();
                $start = CarbonImmutable::parse($mapping['start_date'])->max($today->subYears(3)->addDay());
                // Pending old edits first, then the least recently checked retained dates.
                $pending = TripletexWorkdaySyncState::where('connection_id', $connection->id)->where('user_id', $userId)
                    ->where('status', 'pending')->where('expires_at', '>', now())->limit(10)->pluck('work_date')->all();
                $checked = TripletexWorkdaySyncState::where('connection_id', $connection->id)->where('user_id', $userId)
                    ->pluck('checked_at', 'work_date')->all();
                $dates = [];
                for ($date = $start; $date->lte($today); $date = $date->addDay()) {
                    $dates[] = $date->toDateString();
                }
                usort($dates, fn ($a, $b) => strcmp((string) ($checked[$a] ?? ''), (string) ($checked[$b] ?? '')));
                foreach (array_slice(array_unique(array_merge($pending, [$today->toDateString()], $dates)), 0, 12) as $date) {
                    $sync->day($connection->id, (int) $userId, $date);
                }
            }
        }
        // Retention removes connector evidence only, never provider rows.
        TripletexWorkdaySyncState::where('expires_at', '<=', now())->delete();
        $this->info('Mapped time scan completed. Check integration settings for delivery exceptions.');

        return self::SUCCESS;
    }
}
