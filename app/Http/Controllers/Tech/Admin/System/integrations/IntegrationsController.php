<?php

namespace App\Http\Controllers\Tech\Admin\System\integrations;

use App\Http\Controllers\Controller;
use App\Models\System\Integrations\Integration;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
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
            'client_sync_from' => 'boolean',
            'client_sync_to' => 'boolean',
            'site_sync_from' => 'boolean',
            'site_sync_to' => 'boolean',
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

        $config = $integration->config ?? [];
        $config['client_sync_from'] = $request->boolean('client_sync_from');
        $config['client_sync_to'] = $request->boolean('client_sync_to');
        $config['site_sync_from'] = $request->boolean('site_sync_from');
        $config['site_sync_to'] = $request->boolean('site_sync_to');
        $integration->config = $config;

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
            return back()->with('success', 'N-able RMM settings updated and connection verified.');
        } else {
            return back()->with('warning', 'N-able RMM settings saved, but connection test failed: ' . $integration->last_error);
        }
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
}
