<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Modules\Integration\Models\IntegrationHubAuditEvent;
use App\Modules\Integration\Services\IntegrationHub\ScopeVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends HubController
{
    public function index(Request $request, ScopeVisibility $visibility): JsonResponse
    {
        $page = $this->pagination($request);
        $validated = $request->validate([
            'decision' => ['nullable', 'in:allowed,denied'],
            'result_status' => ['nullable', 'in:ok,denied,unavailable,failed,unknown,stale,partial'],
            'capability_key' => ['nullable', 'string', 'max:160'],
            'correlation_id' => ['nullable', 'uuid'],
        ]);
        $scope = $this->scope($request);
        $query = IntegrationHubAuditEvent::query()
            ->where('installation_key', (string) config('integration-hub.installation_key'));
        $visibility->apply($query, $scope);
        $query->latest('created_at');
        foreach (['decision', 'result_status', 'capability_key', 'correlation_id'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        $paginator = $query->paginate($page['per_page'], ['*'], 'page', $page['page']);
        $items = collect($paginator->items())->map(fn (IntegrationHubAuditEvent $event): array => [
            'id' => $event->id,
            'correlation_id' => $event->correlation_id,
            'execution_id' => $event->execution_id,
            'identity' => ['actor_id' => $event->actor_id, 'workload_id' => $event->workload_id, 'service_actor_id' => $event->service_actor_id],
            'scope' => ['client_id' => $event->client_id, 'site_id' => $event->client_site_id, 'integration_id' => $event->integration_id],
            'capability' => ['key' => $event->capability_key, 'version' => $event->capability_version],
            'decision' => $event->decision,
            'result_status' => $event->result_status,
            'reason_code' => $event->reason_code,
            'source' => $event->source,
            'freshness_status' => $event->freshness_status,
            'duration_ms' => $event->duration_ms,
            'http_status' => $event->http_status,
            'context' => $event->sanitized_context,
            'created_at' => $event->created_at?->toIso8601String(),
        ])->all();

        return $this->result($request, 'ok', $items, ['observed_at' => now(), 'meta' => $this->paginationMeta($paginator)]);
    }
}
