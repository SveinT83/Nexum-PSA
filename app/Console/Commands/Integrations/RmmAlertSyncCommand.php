<?php

namespace App\Console\Commands\Integrations;

use App\Models\System\Integrations\Integration;
use Illuminate\Console\Command;

class RmmAlertSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:rmm-alert-sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronizes alerts from active RMM integrations (Tactical RMM and N-able)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting RMM alert synchronization...');

        // 1. Tactical RMM
        $tacticalIntegration = Integration::where('type', 'tactical_rmm')->where('status', 'active')->first();
        if ($tacticalIntegration) {
            $this->info('Dispatching Tactical RMM alert sync...');
            \App\Jobs\Integrations\Alerts\SyncTacticalAlertsJob::dispatch($tacticalIntegration->id);
        } else {
            $this->line('Tactical RMM integration not active.');
        }

        // 2. N-able RMM
        $nableIntegration = Integration::where('type', 'rmm')->where('status', 'active')->first();
        if ($nableIntegration) {
            $this->info('Dispatching N-able RMM alert sync...');
            \App\Jobs\Integrations\Alerts\SyncNAbleAlertsJob::dispatch($nableIntegration->id);
        } else {
            $this->line('N-able RMM integration not active.');
        }

        $this->info('RMM alert synchronization jobs dispatched.');
        return 0;
    }
}
