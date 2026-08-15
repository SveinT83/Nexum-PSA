<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Models\IntegrationHubDomain;
use App\Modules\Integration\Services\IntegrationHub\ExecutionRecorder;
use App\Modules\Integration\Services\IntegrationHub\PleskReadOnlyAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HostingController extends HubController
{
    public function inspect(Request $request, int $site, ExecutionRecorder $executions, PleskReadOnlyAdapter $adapter): JsonResponse
    {
        $scope = $this->scope($request);
        if (count($scope['site_ids'] ?? []) !== 1 || (int) $scope['site_ids'][0] !== $site
            || count($scope['client_ids'] ?? []) !== 1 || count($scope['integration_ids'] ?? []) !== 1) {
            return $this->notFound($request);
        }

        $siteModel = ClientSite::query()->whereKey($site)->where('client_id', (int) $scope['client_ids'][0])->first();
        $integration = Integration::query()->where('installation_key', (string) config('integration-hub.installation_key'))
            ->whereKey((string) $scope['integration_ids'][0])->where('type', 'plesk')->first();
        if (! $siteModel || ! $integration) {
            return $this->notFound($request);
        }
        $domains = IntegrationHubDomain::query()->where('installation_key', (string) config('integration-hub.installation_key'))
            ->where('client_id', $siteModel->client_id)->where('client_site_id', $siteModel->id)
            ->where('integration_id', $integration->id)->where('environment', $scope['environment'] ?? 'unknown')->get();

        $started = $executions->begin($request, [
            'target_type' => 'client_site', 'target_id' => $siteModel->id,
            'site_id' => $siteModel->id, 'client_id' => $siteModel->client_id,
            'integration_id' => $integration->id, 'domain_ids' => $domains->pluck('id')->all(),
        ]);
        $execution = $started['execution'];
        if ($started['reused']) {
            if ($execution->finished_at) {
                return $this->result($request, $execution->result_status ?: 'unknown', $execution->outcome_summary, [
                    'source_type' => 'provider', 'source_name' => 'plesk',
                    'observed_at' => $execution->verification['observed_at'] ?? $execution->finished_at,
                    'reason_code' => $execution->failure_code,
                    'meta' => ['execution_id' => $execution->id, 'idempotent_replay' => true],
                ], $this->httpStatus($execution->result_status ?: 'unknown'));
            }

            return $this->result($request, 'unavailable', null, [
                'reason_code' => 'execution_in_progress',
                'reason_message' => 'An execution with this idempotency key is still in progress.',
                'retryable' => true,
                'meta' => ['execution_id' => $execution->id, 'idempotent_replay' => true],
            ], 409);
        }

        $executions->step($execution, 1, 'resolve_scope', 'completed', ['site_id' => $siteModel->id, 'integration_id' => $integration->id]);
        $executions->step($execution, 2, 'provider_inspect', 'running');
        $result = $adapter->inspect($integration, $siteModel, $domains, $execution);
        $executions->step($execution, 2, 'provider_inspect', in_array($result['status'], ['ok', 'partial', 'stale'], true) ? 'completed' : $result['status'], null, $result['reason_code']);
        $executions->step($execution, 3, 'verify', $result['status'] === 'ok' ? 'completed' : $result['status'], [
            'provider_hostname_matches' => $result['data']['verification']['provider_hostname_matches'] ?? false,
        ], $result['reason_code']);
        $executions->complete($execution, $result['status'], (array) ($result['data'] ?? []), [
            'observed_at' => $result['observed_at']?->toIso8601String(),
            'provider' => 'plesk',
            'verified' => $result['status'] === 'ok',
        ], $result['reason_code']);

        return $this->result($request, $result['status'], $result['data'], [
            'source_type' => 'provider', 'source_name' => 'plesk',
            'observed_at' => $result['observed_at'],
            'stale_after_seconds' => 300,
            'reason_code' => $result['reason_code'],
            'retryable' => (bool) ($result['retryable'] ?? false),
            'meta' => ['execution_id' => $execution->id, 'idempotent_replay' => false],
        ], $this->httpStatus($result['status']));
    }

    private function httpStatus(string $status): int
    {
        return match ($status) {
            'denied' => 403,
            'unavailable' => 503,
            'failed' => 502,
            'unknown' => 409,
            default => 200,
        };
    }
}
