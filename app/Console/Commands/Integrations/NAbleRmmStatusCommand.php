<?php

namespace App\Console\Commands\Integrations;

use App\Models\System\Integrations\Integration;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Console\Command;

/**
 * NAbleRmmStatusCommand
 *
 * Checks the health and status of the N-able RMM integration
 * without performing any synchronization.
 *
 * @package App\Console\Commands\Integrations
 */
class NAbleRmmStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:nable-rmm-status 
                            {--json : Output as JSON}
                            {--detail : Show detailed client/site counts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check N-able RMM integration status and connection health';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $integration = Integration::where('type', 'rmm')->first();

        if (!$integration) {
            if ($this->option('json')) {
                echo json_encode([
                    'status' => 'not_configured',
                    'message' => 'N-able RMM integration not found',
                ]);
            } else {
                $this->error('❌ N-able RMM integration not found');
                $this->info('Run: php artisan integrations:setup or configure via web UI');
            }
            return 1;
        }

        $client = new NAbleRmmClient($integration);
        
        // Test connection
        $testResult = $client->testConnection();
        
        if (!$testResult['success']) {
            if ($this->option('json')) {
                echo json_encode([
                    'status' => 'connection_failed',
                    'configured' => $client->isConfigured(),
                    'error' => $testResult['error'],
                    'server' => $integration->server,
                ]);
            } else {
                $this->error('❌ Connection failed');
                $this->error('   Error: ' . $testResult['error']);
                $this->info('   Server: ' . $integration->server);
                
                if (!$client->isConfigured()) {
                    $this->warn('   Integration not fully configured');
                }
            }
            return 1;
        }

        // Connection successful - gather stats
        $stats = [
            'status' => 'connected',
            'configured' => true,
            'integration_status' => $integration->status,
            'server' => $integration->server,
            'last_sync' => $integration->last_sync_at?->toIso8601String(),
        ];

        if ($this->option('detail')) {
            $clients = $client->listClients();
            if (!isset($clients['error'])) {
                $stats['client_count'] = count($clients);
                
                // Count devices across all clients
                $totalServers = 0;
                $totalWorkstations = 0;
                $totalDevices = 0;
                
                foreach ($clients as $c) {
                    $totalServers += $c['server_count'] ?? 0;
                    $totalWorkstations += $c['workstation_count'] ?? 0;
                    $totalDevices += $c['device_count'] ?? 0;
                }
                
                $stats['device_summary'] = [
                    'servers' => $totalServers,
                    'workstations' => $totalWorkstations,
                    'total' => $totalDevices,
                ];
            }
        }

        if ($this->option('json')) {
            echo json_encode($stats, JSON_PRETTY_PRINT);
        } else {
            $this->info('✅ N-able RMM Status');
            $this->info('   Server: ' . $stats['server']);
            $this->info('   Integration Status: ' . $stats['integration_status']);
            
            if ($stats['last_sync']) {
                $this->info('   Last Sync: ' . $stats['last_sync']);
            } else {
                $this->warn('   Last Sync: Never');
            }
            
            if (isset($stats['client_count'])) {
                $this->info('   Clients: ' . $stats['client_count']);
                $this->info('   Devices: ' . $stats['device_summary']['total'] . 
                    ' (' . $stats['device_summary']['servers'] . ' servers, ' . 
                    $stats['device_summary']['workstations'] . ' workstations)');
            }
        }

        return 0;
    }
}
