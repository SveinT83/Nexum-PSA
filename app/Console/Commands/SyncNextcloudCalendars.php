<?php

namespace App\Console\Commands;

use App\Modules\Nextcloud\Actions\DispatchDueNextcloudCalendars;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncNextcloudCalendars extends Command
{
    protected $signature = 'nextcloud:sync-calendars
        {--connection= : Connection to configure}
        {--mapping=* : Reviewed mapping IDs for automatic synchronization}
        {--enable : Enable only the explicitly selected mappings without starting a sync}
        {--disable : Pause automatic synchronization without changing mappings}';

    protected $description = 'Dispatch due calendar-only syncs or configure reviewed automatic calendar targets';

    public function handle(DispatchDueNextcloudCalendars $dispatch): int
    {
        if (! $this->option('enable') && ! $this->option('disable')) {
            if ($this->option('connection') !== null || $this->option('mapping') !== []) {
                $this->error('Connection and mapping options require --enable or --disable.');

                return self::INVALID;
            }
            $this->info('Calendar jobs dispatched: '.$dispatch->handle());

            return self::SUCCESS;
        }
        if ($this->option('enable') && $this->option('disable')) {
            $this->error('Choose --enable or --disable.');

            return self::INVALID;
        }
        $id = filter_var($this->option('connection'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ids = array_values(array_unique(array_map(
            fn ($v) => filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            $this->option('mapping'),
        )));
        if (! $id || in_array(false, $ids, true) || ($this->option('enable') && $ids === [])) {
            $this->error('Supply a positive --connection and explicit --mapping IDs when enabling.');

            return self::INVALID;
        }
        $valid = DB::transaction(function () use ($id, $ids) {
            $connection = NextcloudConnection::lockForUpdate()->find($id);
            if (! $connection) {
                return false;
            }
            if ($this->option('enable')) {
                $mappings = $connection->calendarMappings()->whereIn('id', $ids)->where('is_active', true)
                    ->whereNotNull('calendar_id')->with(['calendar', 'user'])->get();
                if (! $connection->is_active || $connection->scope !== 'global'
                    || ! ($connection->settings['calendar_sync_enabled'] ?? false) || $mappings->count() !== count($ids)) {
                    return false;
                }
                foreach ($mappings as $mapping) {
                    if (! $mapping->calendar?->is_active
                        || $mapping->calendar->owner_type !== \App\Models\Core\User::class
                        || (int) $mapping->calendar->owner_id !== (int) $mapping->user_id
                        || ! $mapping->user || $mapping->user->status !== \App\Models\Core\User::STATUS_ACTIVE) {
                        return false;
                    }
                }
                if ($mappings->groupBy('calendar_id')->contains(fn ($group) => $group->count() > 1)) {
                    return false;
                }
            }
            $settings = $connection->settings ?? [];
            $settings['automatic_calendar_sync_enabled'] = (bool) $this->option('enable');
            if ($this->option('enable')) {
                $settings['automatic_calendar_mapping_ids'] = $ids;
                $connection->settings = $settings;
                $settings['automatic_calendar_target_fingerprint'] = DispatchDueNextcloudCalendars::fingerprint($connection);
            }
            $connection->forceFill(['settings' => $settings])->save();

            return true;
        });
        if (! $valid) {
            $this->error('Connection, mapping ownership or scope is not eligible.');

            return self::FAILURE;
        }
        $this->info($this->option('enable')
            ? 'Reviewed calendar targets enabled. No sync was started by this configuration command.'
            : 'Automatic calendar synchronization paused.');

        return self::SUCCESS;
    }
}
