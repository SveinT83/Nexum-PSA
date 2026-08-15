<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\IntegrationHubDomain;
use App\Modules\Integration\Services\IntegrationHub\DomainNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainController extends HubController
{
    public function index(Request $request, DomainNormalizer $normalizer): JsonResponse
    {
        $page = $this->pagination($request);
        $validated = $request->validate([
            'hostname' => ['nullable', 'string', 'max:1024'],
            'client_id' => ['nullable', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'integration_id' => ['nullable', 'uuid'],
            'environment' => ['nullable', 'in:production,staging,development,test,unknown'],
            'lifecycle' => ['nullable', 'in:active,inactive,transferring,orphaned,conflict'],
            'sort' => ['nullable', 'in:hostname,-hostname,id,-id'],
        ]);
        $scope = $this->scope($request);
        $query = IntegrationHubDomain::query()->where('installation_key', (string) config('integration-hub.installation_key'))
            ->whereIn('client_id', $scope['client_ids'] ?? [])->whereIn('client_site_id', $scope['site_ids'] ?? []);
        if (($scope['integration_ids'] ?? []) !== []) {
            $query->where(fn ($q) => $q->whereNull('integration_id')->orWhereIn('integration_id', $scope['integration_ids']));
        }
        if (isset($validated['hostname'])) {
            try {
                $hostname = $normalizer->normalize($validated['hostname'])['ascii'];
            } catch (IntegrationHubDeniedException $exception) {
                return $this->result($request, $exception->resultStatus, null, [
                    'reason_code' => $exception->reasonCode,
                    'reason_message' => $exception->getMessage(),
                    'retryable' => $exception->retryable,
                ], $exception->httpStatus);
            }
            $query->where('hostname_ascii', $hostname);
        }
        foreach (['client_id' => 'client_id', 'site_id' => 'client_site_id', 'integration_id' => 'integration_id', 'environment' => 'environment', 'lifecycle' => 'lifecycle_state'] as $input => $column) {
            if (isset($validated[$input])) {
                $query->where($column, $validated[$input]);
            }
        }
        $sort = $validated['sort'] ?? 'hostname';
        $query->orderBy(ltrim($sort, '-') === 'hostname' ? 'hostname_ascii' : 'id', str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');
        $paginator = $query->paginate($page['per_page'], ['*'], 'page', $page['page']);
        $payloads = collect($paginator->items())->map(fn (IntegrationHubDomain $domain): array => $this->domainPayload($domain))->all();
        $states = collect($payloads)->pluck('state')->unique();
        $status = $states->count() > 1 ? 'partial' : ($states->first() ?? 'ok');
        if (! in_array($status, ['ok', 'unknown', 'stale', 'partial', 'unavailable'], true)) {
            $status = 'partial';
        }

        return $this->result($request, $status, $payloads, [
            'observed_at' => now(), 'meta' => $this->paginationMeta($paginator),
            'reason_code' => $status === 'ok' ? null : 'domain_mapping_state_not_fully_verified',
        ]);
    }

    public function show(Request $request, string $domain): JsonResponse
    {
        $scope = $this->scope($request);
        $model = IntegrationHubDomain::query()->where('installation_key', (string) config('integration-hub.installation_key'))
            ->whereIn('client_id', $scope['client_ids'] ?? [])->whereIn('client_site_id', $scope['site_ids'] ?? [])
            ->whereKey($domain)->first();
        if (! $model || (($scope['integration_ids'] ?? []) !== [] && $model->integration_id && ! in_array((string) $model->integration_id, $scope['integration_ids'], true))) {
            return $this->notFound($request);
        }
        $payload = $this->domainPayload($model);

        return $this->result($request, $payload['state'], $payload, [
            'observed_at' => $model->observed_at,
            'stale_after_seconds' => $model->stale_after_seconds,
            'reason_code' => $payload['state'] === 'ok' ? null : 'domain_mapping_not_verified',
        ]);
    }

    /** @return array<string, mixed> */
    private function domainPayload(IntegrationHubDomain $domain): array
    {
        $state = match (true) {
            $domain->lifecycle_state !== 'active' => 'unavailable',
            ! $domain->observed_at, $domain->verification_status === 'unknown' => 'unknown',
            $domain->observed_at->copy()->addSeconds($domain->stale_after_seconds)->isPast() => 'stale',
            $domain->verification_status === 'verified' => 'ok',
            default => 'partial',
        };

        return [
            'id' => $domain->id,
            'hostname' => $domain->hostname_ascii,
            'display_hostname' => $domain->hostname_unicode,
            'client_id' => $domain->client_id,
            'site_id' => $domain->client_site_id,
            'integration_id' => $domain->integration_id,
            'environment' => $domain->environment,
            'provider_reference' => $domain->provider_reference,
            'lifecycle' => $domain->lifecycle_state,
            'verification' => $domain->verification_status,
            'state' => $state,
            'observed_at' => $domain->observed_at?->toIso8601String(),
            'stale_after_seconds' => $domain->stale_after_seconds,
        ];
    }
}
