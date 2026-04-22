<?php

namespace App\Http\Controllers\Api\WarRoom;

use App\Http\Controllers\Controller;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WarRoom Assets Controller
 *
 * Provides API endpoints for War Room dashboard to fetch
 * asset/device data from N-Able RMM through Nexum PSA.
 *
 * @package App\Http\Controllers\Api\WarRoom
 */
class AssetsController extends Controller
{
    /**
     * List all assets for a client
     *
     * GET /api/warroom/clients/{clientId}/assets
     *
     * @param Request $request
     * @param string $clientId
     * @return JsonResponse
     */
    public function index(Request $request, string $clientId): JsonResponse
    {
        $client = new NAbleRmmClient();

        if (!$client->isConfigured()) {
            return response()->json([
                'error' => 'N-Able RMM integration not configured',
            ], 503);
        }

        $deviceType = $request->get('type', 'server'); // server or workstation
        $devices = $client->listDevices($clientId, $deviceType);

        if (isset($devices['error'])) {
            return response()->json([
                'error' => $devices['error'],
            ], 502);
        }

        // Transform to War Room expected format
        $transformed = array_map(function ($device) use ($deviceType, $clientId) {
            return [
                'id' => $device['id'] ?? null,
                'hostname' => $device['hostname'] ?? $device['name'],
                'name' => $device['name'],
                'type' => $deviceType,
                'site' => $device['site'] ?? 'Unknown',
                'client_id' => $clientId,
                'status' => $device['status'] ?? 'unknown',
                'last_seen' => $device['last_seen'] ?? null,
                'nexum_source' => 'nable_rmm',
            ];
        }, $devices);

        return response()->json([
            'data' => $transformed,
            'meta' => [
                'client_id' => $clientId,
                'device_type' => $deviceType,
                'count' => count($transformed),
                'source' => 'nable_rmm',
            ],
        ]);
    }

    /**
     * Sync assets from N-Able to Nexum PSA
     *
     * POST /api/warroom/clients/{clientId}/assets/sync
     *
     * @param string $clientId
     * @return JsonResponse
     */
    public function sync(string $clientId): JsonResponse
    {
        $client = new NAbleRmmClient();

        if (!$client->isConfigured()) {
            return response()->json([
                'error' => 'N-Able RMM integration not configured',
            ], 503);
        }

        // Get all devices
        $servers = $client->listDevices($clientId, 'server');
        $workstations = $client->listDevices($clientId, 'workstation');

        $synced = [
            'servers' => 0,
            'workstations' => 0,
            'errors' => [],
        ];

        // TODO: Create/update assets in Nexum PSA database
        // This would create Asset models from N-Able data

        return response()->json([
            'message' => 'Asset sync initiated',
            'client_id' => $clientId,
            'stats' => $synced,
            'note' => 'Full sync implementation pending',
        ], 202);
    }

    /**
     * Get asset details
     *
     * GET /api/warroom/assets/{assetId}
     *
     * @param string $assetId
     * @return JsonResponse
     */
    public function show(string $assetId): JsonResponse
    {
        // TODO: Fetch detailed asset info from Nexum database
        // This would use Nexum's Asset model

        return response()->json([
            'message' => 'Asset detail endpoint not yet implemented',
            'asset_id' => $assetId,
        ], 501);
    }
}
