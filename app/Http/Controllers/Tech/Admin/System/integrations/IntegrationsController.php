<?php

namespace App\Http\Controllers\Tech\Admin\System\integrations;

use App\Http\Controllers\Controller;
use App\Models\System\Integrations\Integration;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use App\Services\Integrations\TacticalRmm\TacticalRmmClient;
use Illuminate\Http\Request;

class IntegrationsController extends Controller
{
    public function index()
    {
        $integrations = Integration::all()->keyBy('type');
        return view('tech.admin.system.integrations.index', compact('integrations'));
    }

    public function toggle(Request $request)
    {
        $request->validate([
            'type' => 'required|string',
            'name' => 'required|string',
        ]);

        $integration = Integration::where('type', $request->type)->first();

        if ($integration) {
            $integration->status = $integration->status === 'active' ? 'disabled' : 'active';
            $integration->save();
        } else {
            Integration::create([
                'name' => $request->name,
                'type' => $request->type,
                'status' => 'active',
            ]);
        }

        return back()->with('success', 'Integration status updated.');
    }

    public function nableRmmSettings()
    {
        $integration = Integration::where('type', 'rmm')->first();
        return view('tech.admin.system.integrations.nable_rmm', compact('integration'));
    }

    public function nableRmmUpdate(Request $request)
    {
        $request->validate([
            'server' => 'required|url',
            'api_key' => 'nullable|string',
        ]);

        $integration = Integration::firstOrCreate(
            ['type' => 'rmm'],
            ['name' => 'N-able RMM', 'status' => 'disabled']
        );

        $integration->server = $request->input('server');

        $apiKey = $request->input('api_key') ?: $integration->getSecret('api_key');

        if ($request->has('api_key') && !empty($request->api_key)) {
            $integration->setSecret('api_key', $request->api_key);
        }

        // Test connection
        if ($apiKey && $integration->server) {
            $client = new NAbleRmmClient($integration);
            $client->setCredentials($integration->server, $apiKey);
            $test = $client->testConnection();

            $integration->is_healthy = $test['success'];
            $integration->last_error = $test['success'] ? null : $test['error'];
        }

        $integration->save();

        if ($integration->is_healthy) {
            return back()->with('success', 'N-able RMM API configuration updated and connection verified.');
        } else {
            return back()->with('warning', 'N-able RMM API configuration saved, but connection test failed: ' . $integration->last_error);
        }
    }

    public function nableRmmUpdateSettings(Request $request)
    {
        $request->validate([
            'client_sync_from' => 'boolean',
            'client_sync_to' => 'boolean',
            'site_sync_from' => 'boolean',
            'site_sync_to' => 'boolean',
            'asset_sync_from' => 'boolean',
        ]);

        $integration = Integration::where('type', 'rmm')->firstOrFail();

        $config = $integration->config ?? [];
        $config['client_sync_from'] = $request->boolean('client_sync_from');
        $config['client_sync_to'] = $request->boolean('client_sync_to');
        $config['site_sync_from'] = $request->boolean('site_sync_from');
        $config['site_sync_to'] = $request->boolean('site_sync_to');
        $config['asset_sync_from'] = $request->boolean('asset_sync_from');
        $integration->config = $config;

        $integration->save();

        return back()->with('success', 'N-able RMM automation settings updated.');
    }

    public function nableRmmSyncFrom()
    {
        return back()->with('info', 'Use the interactive sync tool instead.');
    }

    public function nableRmmSyncTo()
    {
        return back()->with('info', 'Use the interactive sync tool instead.');
    }

    public function nableRmmSyncSitesFrom()
    {
        return back()->with('info', 'Use the interactive sync tool instead.');
    }

    public function nableRmmSyncSitesTo()
    {
        return back()->with('info', 'Use the interactive sync tool instead.');
    }

    // TacticalRMM Integration Methods

    public function tacticalRmmSettings()
    {
        $integration = Integration::where('type', 'tacticalrmm')->first();
        $client = new TacticalRmmClient($integration);
        
        $stats = [
            'agent_count' => 0,
            'client_count' => 0,
        ];

        if ($client->isConfigured()) {
            $test = $client->testConnection();
            if ($test['success'] && isset($test['data']['total_agents'])) {
                $stats['agent_count'] = $test['data']['total_agents'];
            }

            $clients = $client->getClients();
            if ($clients['success']) {
                $stats['client_count'] = count($clients['clients']);
            }
        }

        return view('tech.admin.system.integrations.tacticalrmm', compact('integration', 'stats'));
    }

    public function tacticalRmmUpdate(Request $request)
    {
        $request->validate([
            'server' => 'required|url',
            'api_key' => 'nullable|string',
        ]);

        $integration = Integration::firstOrCreate(
            ['type' => 'tacticalrmm'],
            ['name' => 'TacticalRMM', 'status' => 'disabled']
        );

        $integration->server = $request->input('server');

        $apiKey = $request->input('api_key') ?: $integration->getSecret('api_key');

        if ($request->has('api_key') && !empty($request->api_key)) {
            $integration->setSecret('api_key', $request->api_key);
        }

        // Test connection
        if ($apiKey && $integration->server) {
            $client = new TacticalRmmClient($integration);
            $test = $client->testConnection();

            $integration->is_healthy = $test['success'];
            $integration->last_error = $test['success'] ? null : ($test['error'] ?? 'Connection failed');
        }

        $integration->save();

        if ($integration->is_healthy) {
            return back()->with('success', 'TacticalRMM API configuration updated and connection verified.');
        } else {
            return back()->with('warning', 'TacticalRMM API configuration saved, but connection test failed: ' . ($integration->last_error ?? 'Unknown error'));
        }
    }

    public function tacticalRmmUpdateSettings(Request $request)
    {
        $request->validate([
            'client_sync_from' => 'boolean',
            'asset_sync_from' => 'boolean',
        ]);

        $integration = Integration::where('type', 'tacticalrmm')->firstOrFail();

        $config = $integration->config ?? [];
        $config['client_sync_from'] = $request->boolean('client_sync_from');
        $config['asset_sync_from'] = $request->boolean('asset_sync_from');
        $integration->config = $config;

        $integration->save();

        return back()->with('success', 'TacticalRMM automation settings updated.');
    }

    public function tacticalRmmSync(Request $request)
    {
        $client = new TacticalRmmClient();

        if (!$client->isConfigured()) {
            return back()->with('error', 'TacticalRMM integration not configured.');
        }

        $result = $client->syncAll();

        if ($result['success']) {
            $integration = Integration::where('type', 'tacticalrmm')->first();
            if ($integration) {
                $integration->update([
                    'last_sync_at' => now(),
                    'is_healthy' => true,
                ]);
            }
            return back()->with('success', sprintf(
                'Sync completed: %d clients, %d sites, %d agents.',
                $result['stats']['clients_synced'],
                $result['stats']['sites_synced'],
                $result['stats']['agents_synced']
            ));
        } else {
            return back()->with('error', 'Sync failed: ' . ($result['error'] ?? 'Unknown error'));
        }
    }

    public function tacticalRmmTest(Request $request)
    {
        $client = new TacticalRmmClient();

        if (!$client->isConfigured()) {
            return back()->with('error', 'TacticalRMM integration not configured.');
        }

        $result = $client->testConnection();

        if ($result['success']) {
            $integration = Integration::where('type', 'tacticalrmm')->first();
            if ($integration) {
                $integration->update([
                    'is_healthy' => true,
                    'last_error' => null,
                ]);
            }
            return back()->with('success', 'Connection test successful! Found ' . ($result['data']['total_agents'] ?? 'unknown') . ' agents.');
        } else {
            $integration = Integration::where('type', 'tacticalrmm')->first();
            if ($integration) {
                $integration->update([
                    'is_healthy' => false,
                    'last_error' => $result['error'] ?? 'Connection failed',
                ]);
            }
            return back()->with('error', 'Connection test failed: ' . ($result['error'] ?? 'Unknown error'));
        }
    }
}
