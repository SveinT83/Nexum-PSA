<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\IntegrationHubCapability;
use App\Modules\Integration\Services\IntegrationHub\CapabilityPolicy;
use App\Modules\Integration\Services\IntegrationHub\CapabilityRegistry;
use App\Modules\Integration\Services\IntegrationHub\ScopeValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class CapabilityController extends HubController
{
    public function index(Request $request, CapabilityRegistry $registry, CapabilityPolicy $policy, ScopeValidator $scopeValidator): JsonResponse
    {
        $page = $this->pagination($request);
        $context = $this->delegatedContext($request);
        $effective = IntegrationHubCapability::query()->where('enabled', true)->where('lifecycle_state', 'active')
            ->orderBy('capability_key')->orderBy('contract_version')->get()
            ->filter(function (IntegrationHubCapability $capability) use ($context, $policy, $scopeValidator): bool {
                try {
                    $scope = $scopeValidator->resolve($capability, $context['scope'], $context['workload']);
                    $policy->assertAllowed($capability, $context['actor'], $context['token'], $context['workload'], $scope);

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            })->values();

        $total = $effective->count();
        $items = $effective->forPage($page['page'], $page['per_page'])->map(fn (IntegrationHubCapability $capability): array => $registry->externalDescriptor($capability))->values()->all();

        return $this->result($request, 'ok', $items, [
            'observed_at' => now(),
            'meta' => ['pagination' => [
                'current_page' => $page['page'], 'per_page' => $page['per_page'], 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $page['per_page'])),
            ]],
        ]);
    }

    public function show(Request $request, string $key, string $version, CapabilityRegistry $registry, CapabilityPolicy $policy, ScopeValidator $scopeValidator): JsonResponse
    {
        $capability = $registry->findCompatible($key, $version);
        if (! $capability) {
            return $this->result($request, 'failed', null, [
                'reason_code' => 'contract_version_unsupported',
                'reason_message' => 'Capability contract is unsupported.',
            ], 409);
        }

        $context = $this->delegatedContext($request);
        try {
            $scope = $scopeValidator->resolve($capability, $context['scope'], $context['workload']);
            $policy->assertAllowed($capability, $context['actor'], $context['token'], $context['workload'], $scope);
        } catch (\Throwable) {
            return $this->notFound($request);
        }

        return $this->result($request, 'ok', $registry->externalDescriptor($capability), ['observed_at' => now()]);
    }

    /** @return array{actor:\App\Models\Core\User,token:PersonalAccessToken,workload:?AiWorkloadProfile,scope:array<string,mixed>} */
    private function delegatedContext(Request $request): array
    {
        $grant = $request->attributes->get('integration_hub_grant_record');
        $token = PersonalAccessToken::query()->findOrFail($grant->issued_by_token_id);

        return [
            'actor' => $request->attributes->get('integration_hub_delegated_actor'),
            'token' => $token,
            'workload' => $request->attributes->get('integration_hub_workload'),
            'scope' => $this->scope($request),
        ];
    }
}
