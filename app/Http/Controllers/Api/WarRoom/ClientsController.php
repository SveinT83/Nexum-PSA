<?php

namespace App\Http\Controllers\Api\WarRoom;

use App\Http\Controllers\Controller;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WarRoom Clients Controller
 *
 * Provides API endpoints for War Room dashboard to fetch
 * N-Able RMM client data through Nexum PSA.
 *
 * @package App\Http\Controllers\Api\WarRoom
 */
class ClientsController extends Controller
{
    /**
     * List all clients from N-Able RMM
     *
     * GET /api/warroom/clients
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $client = new NAbleRmmClient();

        if (!$client->isConfigured()) {
            return response()->json([
                'error' => 'N-Able RMM integration not configured',
                'configured' => false,
            ], 503);
        }

        $clients = $client->listClients();

        if (isset($clients['error'])) {
            return response()->json([
                'error' => $clients['error'],
                'configured' => true,
            ], 502);
        }

        // Transform to War Room expected format
        $transformed = array_map(function ($client) {
            return [
                'id' => $client['clientid'],
                'name' => $client['name'],
                'device_counts' => [
                    'servers' => (int) ($client['server_count'] ?? 0),
                    'workstations' => (int) ($client['workstation_count'] ?? 0),
                    'mobile' => (int) ($client['mobile_device_count'] ?? 0),
                    'total' => (int) ($client['device_count'] ?? 0),
                ],
                'timezone' => $client['timezone'] ?? 'UTC',
                'created_at' => $client['creation_date'] ?? null,
                // Nexum-specific fields
                'nexum_source' => 'nable_rmm',
            ];
        }, $clients);

        return response()->json([
            'data' => $transformed,
            'meta' => [
                'count' => count($transformed),
                'source' => 'nable_rmm',
                'cached_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get detailed client info including sites
     *
     * GET /api/warroom/clients/{id}
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $client = new NAbleRmmClient();

        if (!$client->isConfigured()) {
            return response()->json([
                'error' => 'N-Able RMM integration not configured',
            ], 503);
        }

        // Get client sites
        $sites = $client->listSites($id);

        if (isset($sites['error'])) {
            return response()->json([
                'error' => $sites['error'],
            ], 502);
        }

        // Get client devices
        $servers = $client->listDevices($id, 'server');
        $workstations = $client->listDevices($id, 'workstation');

        return response()->json([
            'data' => [
                'id' => $id,
                'sites' => $sites,
                'devices' => [
                    'servers' => isset($servers['error']) ? [] : $servers,
                    'workstations' => isset($workstations['error']) ? [] : $workstations,
                ],
            ],
        ]);
    }

    /**
     * Sync client to Nexum PSA (if not exists)
     *
     * POST /api/warroom/clients/{id}/sync
     *
     * @param string $id
     * @return JsonResponse
     */
    public function sync(string $id): JsonResponse
    {
        // TODO: Implement client sync from N-Able to Nexum
        // This would create the client in Nexum PSA if not exists

        return response()->json([
            'message' => 'Sync not yet implemented',
            'client_id' => $id,
        ], 501);
    }
}
