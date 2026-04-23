<?php

namespace App\Console\Commands\Integrations;

use App\Models\System\Integrations\Integration;
use App\Jobs\Integrations\TacticalRmm\SyncTacticalClientsJob;
use App\Jobs\Integrations\TacticalRmm\SyncTacticalAssetsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TacticalRmmSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:tactical-rmm-sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronizes assets, clients, and sites from Tactical RMM based on integration settings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $integration = Integration::where('type', 'tactical_rmm')->where('status', 'active')->first();

        if (!$integration) {
            $this->info('Tactical RMM integration is not active. Skipping sync.');
            return 0;
        }

        $config = $integration->config ?? [];

        // 1. Sync Clients and Sites (merged)
        if (!empty($config['client_sync_from'])) {
            $this->info('Dispatching Tactical RMM Client and Site sync job...');
            // Dispatch sync job without cache key for automation
            SyncTacticalClientsJob::dispatch($integration->id);
        }

        // 2. Sync Assets
        if (!empty($config['asset_sync_from'])) {
            $this->info('Dispatching Tactical RMM Asset sync job...');
            SyncTacticalAssetsJob::dispatch($integration->id);
        }

        $this->info('Tactical RMM synchronization jobs dispatched.');
        return 0;
    }
}
