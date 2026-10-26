<?php

namespace App\Modules\Asset\Actions;

use App\Jobs\Integrations\TacticalRmm\SyncTacticalAssetsJob;
use App\Models\System\Integrations\Integration;
use App\Services\Integrations\TacticalRmm\TacticalRmmClient;

/** The canonical job owns mapping; filter the effective Client/Site before invoking it. */
class ImportTacticalAssets
{
    public function handle(Integration $integration, ?int $clientId, ?int $siteId, bool $preview = false): array
    {
        abort_unless($integration->server && $integration->getSecret('api_key'), 409, 'Provider credentials are not configured.');
        $client = app(TacticalRmmClient::class, ['baseUrl' => $integration->server, 'apiKey' => $integration->getSecret('api_key')]);
        // Provider site IDs and Nexum IDs are different namespaces. Filter locally after mapping.
        $agents = $client->getAgents();
        if (! $client->lastRequestSuccessful) {
            return ['state' => 'failed', 'candidates' => 0, 'created' => 0, 'updated' => 0, 'errors' => 1];
        }
        $job = new SyncTacticalAssetsJob($integration->id, null, $siteId);
        $result = ['success' => 0, 'updated' => 0, 'linked' => 0, 'errors' => 0, 'error_list' => [], 'candidates' => 0];
        foreach ($agents as $agent) {
            if (! is_array($agent)) {
                $result['errors']++;

                continue;
            }
            if (! isset($agent['client'], $agent['site'])) {
                $id = $agent['agent_id'] ?? $agent['pk'] ?? null;
                if ($id) {
                    $agent = array_merge($agent, $client->getAgentDetails((string) $id));
                }
            }
            $localClient = $job->resolveClient($agent, $integration);
            $site = $job->resolveSite($agent, $integration);
            if (! $localClient || ! $site || (int) $site->client_id !== (int) $localClient->id) {
                $result['errors']++;

                continue;
            }
            if (($clientId && (int) $localClient->id !== $clientId) || ($siteId && (int) $site->id !== $siteId)) {
                continue;
            }
            $result['candidates']++;
            if ($preview) {
                continue;
            }
            try {
                $job->processAgent($agent, $integration, $result);
            } catch (\Throwable $error) {
                $result['errors']++;
            }
        }

        return ['state' => $result['errors'] ? 'partial' : 'completed', 'candidates' => $result['candidates'],
            'created' => $result['success'], 'updated' => $result['updated'], 'errors' => $result['errors']];
    }
}
