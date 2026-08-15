<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Models\IntegrationHubCapability;
use App\Modules\Integration\Models\IntegrationHubEmergencyControl;
use App\Modules\Integration\Services\IntegrationHub\IntegrationHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationController extends HubController
{
    public function index(Request $request, IntegrationHealth $health): JsonResponse
    {
        $page = $this->pagination($request);
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:80'],
            'environment' => ['nullable', 'in:production,staging,development,test,unknown'],
            'owner_scope' => ['nullable', 'in:internal,installation,client,site'],
            'status' => ['nullable', 'in:active,disabled'],
            'sort' => ['nullable', 'in:name,-name,type,-type,id,-id'],
        ]);
        $query = $this->visibleQuery($request);
        foreach (['type', 'environment', 'owner_scope', 'status'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        $sort = $validated['sort'] ?? 'name';
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');
        $paginator = $query->paginate($page['per_page'], ['*'], 'page', $page['page']);
        $items = collect($paginator->items())->map(fn (Integration $integration): array => $this->integrationPayload($integration, $health))->all();
        $states = collect($items)->pluck('health.status')->unique();
        $status = $states->count() > 1 ? 'partial' : ($states->first() ?? 'ok');

        return $this->result($request, in_array($status, ['ok', 'unavailable', 'failed', 'unknown', 'stale', 'partial'], true) ? $status : 'unknown', $items, [
            'observed_at' => now(), 'meta' => $this->paginationMeta($paginator),
            'reason_code' => $status === 'ok' ? null : 'integration_catalogue_contains_non_healthy_state',
        ]);
    }

    public function show(Request $request, string $integration, IntegrationHealth $health): JsonResponse
    {
        $model = $this->visibleQuery($request)->whereKey($integration)->first();
        if (! $model) {
            return $this->notFound($request);
        }
        $payload = $this->integrationPayload($model, $health);

        return $this->result($request, $payload['health']['status'], $payload, [
            'observed_at' => $payload['health']['observed_at'],
            'stale_after_seconds' => $payload['health']['stale_after_seconds'],
            'reason_code' => $payload['health']['reason_code'],
        ]);
    }

    public function health(Request $request, string $integration, IntegrationHealth $health): JsonResponse
    {
        $model = $this->visibleQuery($request)->whereKey($integration)->first();
        if (! $model) {
            return $this->notFound($request);
        }
        $payload = $health->payload($model);

        return $this->result($request, $payload['status'], $payload, [
            'observed_at' => $payload['observed_at'], 'stale_after_seconds' => $payload['stale_after_seconds'],
            'reason_code' => $payload['reason_code'], 'source_type' => 'provider', 'source_name' => $model->type,
        ]);
    }

    private function visibleQuery(Request $request)
    {
        $scope = $this->scope($request);

        return Integration::query()->where('installation_key', (string) config('integration-hub.installation_key'))
            ->whereIn('id', $scope['integration_ids'] ?? [])
            ->where(function ($owner) use ($scope): void {
                $owner->whereIn('owner_scope', ['internal', 'installation'])
                    ->orWhere(fn ($q) => $q->where('owner_scope', 'client')->whereIn('client_id', $scope['client_ids'] ?? []))
                    ->orWhere(fn ($q) => $q->where('owner_scope', 'site')->whereIn('client_site_id', $scope['site_ids'] ?? []));
            });
    }

    /** @return array<string, mixed> */
    private function integrationPayload(Integration $integration, IntegrationHealth $health): array
    {
        $healthPayload = $health->payload($integration);
        $capabilities = IntegrationHubCapability::query()
            ->where('enabled', true)
            ->where('lifecycle_state', 'active')
            ->whereJsonContains('provider_types', $integration->type)
            ->whereHas('bindings', function ($binding) use ($integration): void {
                $binding->where('installation_key', (string) config('integration-hub.installation_key'))
                    ->where('enabled', true)
                    ->whereNull('actor_kind')->whereNull('actor_id')->whereNull('role_name')->whereNull('workload_id')
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->where(fn ($query) => $query->whereNull('client_id')->orWhere('client_id', $integration->client_id))
                    ->where(fn ($query) => $query->whereNull('client_site_id')->orWhere('client_site_id', $integration->client_site_id))
                    ->where(fn ($query) => $query->whereNull('integration_id')->orWhere('integration_id', $integration->id))
                    ->where(fn ($query) => $query->whereNull('environment')->orWhere('environment', $integration->environment));
            })
            ->orderBy('capability_key')->get(['capability_key', 'contract_version'])->map(fn ($capability): array => [
                'key' => $capability->capability_key, 'version' => $capability->contract_version,
            ])->all();
        $disabled = IntegrationHubEmergencyControl::query()->where('installation_key', (string) config('integration-hub.installation_key'))
            ->where('control_key', 'integration:'.$integration->id)->where('is_disabled', true)->exists();

        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'provider_type' => $integration->type,
            'owner' => ['scope' => $integration->owner_scope, 'client_id' => $integration->client_id, 'site_id' => $integration->client_site_id],
            'organization' => $integration->installation_key,
            'environment' => $integration->environment,
            'lifecycle' => $integration->status,
            'emergency_disabled' => $disabled,
            'credential' => $healthPayload['credential'],
            'capabilities' => $capabilities,
            'health' => [
                'status' => $healthPayload['status'],
                'reason_code' => $healthPayload['reason_code'],
                'observed_at' => $healthPayload['observed_at']?->toIso8601String(),
                'stale_after_seconds' => $healthPayload['stale_after_seconds'],
                'last_successful_observation_at' => $healthPayload['last_successful_observation_at'],
            ],
        ];
    }
}
