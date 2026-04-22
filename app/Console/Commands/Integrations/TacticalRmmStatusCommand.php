<?php

namespace App\Console\Commands\Integrations;

use App\Services\Integrations\TacticalRmm\TacticalRmmClient;
use App\Models\System\Integrations\Integration;
use Illuminate\Console\Command;

class TacticalRmmStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:tacticalrmm-status
                            {--test : Test connection to TacticalRMM}
                            {--sync : Sync all data from TacticalRMM}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check TacticalRMM integration status and perform operations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $client = new TacticalRmmClient();

        // Check if configured
        if (!$client->isConfigured()) {
            $integration = Integration::where('type', 'tacticalrmm')->first();
            
            if (!$integration) {
                $this->error('TacticalRMM integration not found in database.');
                $this->info('Run: php artisan integrations:tacticalrmm-setup');
                return 1;
            }

            if (!$integration->server) {
                $this->error('TacticalRMM server URL not configured.');
            }

            $apiKey = $integration->getSecret('api_key');
            if (!$apiKey) {
                $this->error('TacticalRMM API key not configured.');
            }

            if ($integration->status !== 'active') {
                $this->error('TacticalRMM integration status: ' . $integration->status);
            }

            return 1;
        }

        $integration = Integration::where('type', 'tacticalrmm')->first();
        
        // Show current configuration
        if (!$this->option('test') && !$this->option('sync')) {
            $this->info('TacticalRMM Integration Configuration:');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Name', $integration->name],
                    ['Server', $integration->server],
                    ['Status', $integration->status],
                    ['Last Sync', $integration->last_sync_at?->format('Y-m-d H:i:s') ?? 'Never'],
                    ['Healthy', $integration->is_healthy ? 'Yes' : 'No'],
                ]
            );

            if ($integration->last_error) {
                $this->warn('Last Error: ' . $integration->last_error);
            }

            $this->info('Use --test to check connection or --sync to sync data.');
            return 0;
        }

        // Test connection
        if ($this->option('test')) {
            $this->info('Testing connection to TacticalRMM...');
            
            $result = $client->testConnection();

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
                return $result['success'] ? 0 : 1;
            }

            if ($result['success']) {
                $this->info('✓ Connection successful!');
                if (isset($result['data']['total_agents'])) {
                    $this->info('  Total agents: ' . $result['data']['total_agents']);
                }
                
                // Update integration health
                $integration->update([
                    'is_healthy' => true,
                    'last_error' => null,
                ]);
                
                return 0;
            } else {
                $this->error('✗ Connection failed: ' . $result['error']);
                
                // Update integration health
                $integration->update([
                    'is_healthy' => false,
                    'last_error' => $result['error'],
                ]);
                
                return 1;
            }
        }

        // Sync data
        if ($this->option('sync')) {
            $this->info('Syncing data from TacticalRMM...');
            
            $result = $client->syncAll();

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
                return $result['success'] ? 0 : 1;
            }

            if ($result['success']) {
                $this->info('✓ Sync completed successfully!');
                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Clients Synced', $result['stats']['clients_synced']],
                        ['Sites Synced', $result['stats']['sites_synced']],
                        ['Agents Synced', $result['stats']['agents_synced']],
                    ]
                );

                // Update integration
                $integration->update([
                    'last_sync_at' => now(),
                    'is_healthy' => true,
                    'last_error' => null,
                ]);
                
                return 0;
            } else {
                $this->error('✗ Sync failed: ' . $result['error']);
                
                $integration->update([
                    'is_healthy' => false,
                    'last_error' => $result['error'],
                ]);
                
                return 1;
            }
        }

        return 0;
    }
}
