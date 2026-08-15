<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Modules\Integration\Models\IntegrationHubExecution;
use App\Modules\Integration\Services\IntegrationHub\ScopeVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutionController extends HubController
{
    public function __construct(private ScopeVisibility $visibility) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->pagination($request);
        $validated = $request->validate([
            'status' => ['nullable', 'in:queued,running,input_required,partial,failed,unknown,completed,cancelled'],
            'capability_key' => ['nullable', 'string', 'max:160'],
            'correlation_id' => ['nullable', 'uuid'],
        ]);
        $query = $this->visibleQuery($request)->latest('created_at');
        foreach (['status', 'capability_key', 'correlation_id'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        $paginator = $query->paginate($page['per_page'], ['*'], 'page', $page['page']);

        return $this->result($request, 'ok', collect($paginator->items())->map(fn (IntegrationHubExecution $execution): array => $this->payload($execution))->all(), [
            'observed_at' => now(), 'meta' => $this->paginationMeta($paginator),
        ]);
    }

    public function show(Request $request, string $execution): JsonResponse
    {
        $model = $this->visibleQuery($request)->with(['steps', 'approvals.decision'])->whereKey($execution)->first();

        return $model ? $this->result($request, 'ok', $this->payload($model), ['observed_at' => $model->updated_at]) : $this->notFound($request);
    }

    private function visibleQuery(Request $request)
    {
        $scope = $this->scope($request);
        $query = IntegrationHubExecution::query()->where('installation_key', (string) config('integration-hub.installation_key'));
        $this->visibility->apply($query, $scope);

        return $query;
    }

    /** @return array<string, mixed> */
    private function payload(IntegrationHubExecution $execution): array
    {
        return [
            'id' => $execution->id,
            'correlation_id' => $execution->correlation_id,
            'identity' => ['actor_id' => $execution->actor_id, 'workload_id' => $execution->workload_id, 'service_actor_id' => $execution->service_actor_id],
            'scope' => ['organization' => $execution->installation_key, 'client_id' => $execution->client_id, 'site_id' => $execution->client_site_id, 'integration_id' => $execution->integration_id, 'environment' => $execution->environment],
            'capability' => ['key' => $execution->capability_key, 'version' => $execution->capability_version],
            'target' => ['type' => $execution->target_type, 'id' => $execution->target_id],
            'status' => $execution->status,
            'result_status' => $execution->result_status,
            'failure_code' => $execution->failure_code,
            'request_summary' => $execution->request_summary,
            'outcome_summary' => $execution->outcome_summary,
            'verification' => $execution->verification,
            'steps' => $execution->relationLoaded('steps') ? $execution->steps->map(fn ($step): array => [
                'sequence' => $step->sequence, 'key' => $step->step_key, 'status' => $step->status,
                'attempt' => $step->attempt, 'failure_code' => $step->failure_code,
            ])->all() : null,
            'approvals' => $execution->relationLoaded('approvals') ? $execution->approvals->map(fn ($approval): array => [
                'id' => $approval->id, 'status' => $approval->status, 'risk_level' => $approval->risk_level,
                'expires_at' => $approval->expires_at?->toIso8601String(), 'decided_at' => $approval->decided_at?->toIso8601String(),
                'decision' => $approval->decision?->decision,
            ])->all() : null,
            'timestamps' => ['created_at' => $execution->created_at?->toIso8601String(), 'started_at' => $execution->started_at?->toIso8601String(), 'finished_at' => $execution->finished_at?->toIso8601String(), 'cancelled_at' => $execution->cancelled_at?->toIso8601String()],
        ];
    }
}
