<?php

namespace App\Console\Commands\Integrations;

use App\Models\System\Integrations\Integration;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TacticalRmmSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'integrations:tacticalrmm-setup
                            {--server= : TacticalRMM server URL}
                            {--apikey= : API Key for authentication}
                            {--force : Update existing integration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up or update TacticalRMM integration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Check if integration already exists
        $existing = Integration::where('type', 'tacticalrmm')->first();
        
        if ($existing && !$this->option('force')) {
            $this->warn('TacticalRMM integration already exists.');
            $this->info('Server: ' . $existing->server);
            $this->info('Status: ' . $existing->status);
            $this->info('Use --force to update existing configuration.');
            return 1;
        }

        // Get server URL
        $server = $this->option('server');
        if (!$server) {
            $server = $this->ask('Enter TacticalRMM server URL (e.g., https://api.td-network.no)');
        }

        // Validate URL
        if (!filter_var($server, FILTER_VALIDATE_URL)) {
            $this->error('Invalid URL format.');
            return 1;
        }

        // Get API key
        $apiKey = $this->option('apikey');
        if (!$apiKey) {
            $apiKey = $this->secret('Enter TacticalRMM API Key');
        }

        if (empty($apiKey)) {
            $this->error('API Key cannot be empty.');
            return 1;
        }

        // Create or update integration
        if ($existing) {
            $existing->server = $server;
            $existing->setSecret('api_key', $apiKey);
            $existing->status = 'active';
            $existing->save();
            
            $this->info('Updated existing TacticalRMM integration.');
        } else {
            $integration = new Integration([
                'id' => (string) Str::uuid(),
                'name' => 'TacticalRMM',
                'type' => 'tacticalrmm',
                'server' => $server,
                'status' => 'active',
                'config' => [],
                'secrets' => [],
            ]);
            
            $integration->setSecret('api_key', $apiKey);
            $integration->save();
            
            $this->info('Created new TacticalRMM integration.');
        }

        $this->info('');
        $this->info('You can now test the connection with:');
        $this->info('  php artisan integrations:tacticalrmm-status --test');
        
        return 0;
    }
}
