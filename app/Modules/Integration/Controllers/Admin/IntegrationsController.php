<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Services\BookStack\BookStackClient;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use App\Services\Integrations\TacticalRmm\TacticalRmmClient;
use Illuminate\Http\Request;

class IntegrationsController extends Controller
{
    public function index()
    {
        $integrations = Integration::all()->keyBy('type');
        return view('integration::Tech.Admin.System.Integrations.index', compact('integrations'));
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
            if ($integration->status === 'active' && $integration->is_healthy === null) {
                $integration->is_healthy = false;
            }
            $integration->save();
        } else {
            Integration::create([
                'name' => $request->name,
                'type' => $request->type,
                'status' => 'active',
                'is_healthy' => false,
            ]);
        }

        return back()->with('success', 'Integration status updated.');
    }

    public function nableRmmSettings()
    {
        $integration = Integration::where('type', 'rmm')->first();
        return view('integration::Tech.Admin.System.Integrations.nable.nable_rmm', compact('integration'));
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

    public function tacticalRmmSettings()
    {
        $integration = Integration::where("type", "tactical_rmm")->first();

        if ($integration && $integration->server && $integration->getSecret("api_key")) {
            $client = new TacticalRmmClient($integration->server, $integration->getSecret("api_key"));

            // Connection Health check
            $test = $client->testConnection();
            $integration->is_healthy = $test["success"];
            $integration->last_error = $test["success"] ? null : $test["message"];
            $integration->save();
        }

        return view("integration::Tech.Admin.System.Integrations.tactical.settings", compact("integration"));
    }

    public function tacticalRmmUpdate(Request $request)
    {
        $request->validate([
            'server' => 'required|url',
            'api_key' => 'nullable|string',
        ]);

        $integration = Integration::firstOrCreate(
            ['type' => 'tactical_rmm'],
            ['name' => 'Tactical RMM', 'status' => 'disabled']
        );

        $integration->server = $request->input('server');

        $apiKey = $request->input('api_key') ?: $integration->getSecret('api_key');

        if ($request->has('api_key') && !empty($request->api_key)) {
            $integration->setSecret('api_key', $request->api_key);
        }

        // Test connection
        if ($apiKey && $integration->server) {
            $client = new TacticalRmmClient($integration->server, $apiKey);
            $test = $client->testConnection();

            $integration->is_healthy = $test['success'];
            $integration->last_error = $test['success'] ? null : $test['message'];
        }

        $integration->save();

        if ($integration->is_healthy) {
            return back()->with('success', 'Tactical RMM API configuration updated and connection verified.');
        } else {
            return back()->with('warning', 'Tactical RMM API configuration saved, but connection test failed: ' . $integration->last_error);
        }
    }

    public function tacticalRmmUpdateSettings(Request $request)
    {
        $request->validate([
            'client_sync_from' => 'boolean',
            'asset_sync_from' => 'boolean',
        ]);

        $integration = Integration::where('type', 'tactical_rmm')->firstOrFail();

        $config = $integration->config ?? [];
        $config['client_sync_from'] = $request->boolean('client_sync_from');
        // We merged site_sync_from into client_sync_from as per user request
        unset($config['site_sync_from']);
        $config['asset_sync_from'] = $request->boolean('asset_sync_from');
        $integration->config = $config;

        $integration->save();

        return back()->with('success', 'Tactical RMM automation settings updated.');
    }

    public function bookStackSettings()
    {
        $integration = Integration::where('type', 'book_stack')->first();

        return view('integration::Tech.Admin.System.Integrations.book_stack.settings', compact('integration'));
    }

    public function bookStackUpdate(Request $request)
    {
        $request->validate([
            'server' => 'required|url',
            'token_id' => 'nullable|string',
            'token_secret' => 'nullable|string',
            'sync_interval_minutes' => 'nullable|integer|min:1|max:1440',
        ]);

        $integration = Integration::firstOrCreate(
            ['type' => 'book_stack'],
            ['name' => 'BookStack', 'status' => 'disabled']
        );

        $integration->server = rtrim($request->input('server'), '/');

        $tokenId = $request->input('token_id') ?: $integration->getSecret('token_id');
        $tokenSecret = $request->input('token_secret') ?: $integration->getSecret('token_secret');

        if ($request->filled('token_id')) {
            $integration->setSecret('token_id', $request->input('token_id'));
        }

        if ($request->filled('token_secret')) {
            $integration->setSecret('token_secret', $request->input('token_secret'));
        }

        $config = $integration->config ?? [];
        $config['sync_interval_minutes'] = (int) $request->input('sync_interval_minutes', $config['sync_interval_minutes'] ?? 5);
        $config['read_only'] = true;
        $config['provider_role'] = 'knowledge_source';
        $integration->config = $config;

        if (!$tokenId || !$tokenSecret) {
            $integration->is_healthy = false;
            $integration->last_error = null;
        }

        $integration->save();

        return back()->with('success', 'BookStack configuration saved.');
    }

    public function bookStackTestConnection()
    {
        $integration = Integration::where('type', 'book_stack')->firstOrFail();
        $tokenId = $integration->getSecret('token_id');
        $tokenSecret = $integration->getSecret('token_secret');

        if ($tokenId && $tokenSecret && $integration->server) {
            $client = new BookStackClient($integration->server, $tokenId, $tokenSecret);
            $test = $client->testConnection();

            $integration->is_healthy = $test['success'];
            $integration->last_error = $test['success'] ? null : $test['message'];
        } else {
            $integration->is_healthy = false;
            $integration->last_error = 'BookStack token ID and token secret are required.';
        }

        $integration->save();

        if ($integration->is_healthy) {
            return back()->with('success', 'BookStack connection verified.');
        }

        return back()->with('warning', 'BookStack connection test failed: ' . $integration->last_error);
    }
}
