<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\AiPolicyDeniedException;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiOutboundPolicyGuard;

class MailAiAgentRuntime
{
    public function __construct(
        private readonly AiAgentResolver $agentResolver,
        private readonly AiOutboundPolicyGuard $policyGuard,
    ) {}

    /**
     * @return array{available: bool, reason: string|null, agent: AiAgent|null, model: string|null}
     */
    public function availability(User $user): array
    {
        $agent = $this->agentResolver->defaultAgent($user, 'email');

        if (! $agent) {
            return $this->unavailable('default_agent_not_available');
        }

        $agent->loadMissing('provider');
        $provider = $agent->provider;

        if (! $provider || $provider->status !== 'active') {
            return $this->unavailable('agent_provider_not_active', $agent);
        }

        $model = trim((string) ($agent->model ?: $provider->default_model));
        if ($model === '') {
            return $this->unavailable('agent_model_missing', $agent);
        }

        try {
            $this->policyGuard->authorizeAgent($agent, $model);
        } catch (AiPolicyDeniedException $exception) {
            return $this->unavailable($exception->reasonCode, $agent, $model);
        }

        return [
            'available' => true,
            'reason' => null,
            'agent' => $agent,
            'model' => $model,
        ];
    }

    /**
     * @param  array<int, string>  $requiredApiScopes
     * @return array{available: bool, reason: string|null, agent: AiAgent|null, model: string|null}
     */
    public function writeAvailability(User $user, array $requiredApiScopes): array
    {
        $availability = $this->availability($user);

        if (! $availability['available']) {
            return $availability;
        }

        $agent = $availability['agent'];

        if (! $agent?->can_execute_actions) {
            return $this->unavailable('agent_action_execution_disabled', $agent, $availability['model']);
        }

        $allowedScopes = collect($agent->allowed_api_scopes ?? [])
            ->map(fn (mixed $scope): string => trim((string) $scope))
            ->filter()
            ->values();

        if (! $allowedScopes->contains('*')) {
            $missing = collect($requiredApiScopes)
                ->map(fn (string $scope): string => trim($scope))
                ->filter()
                ->first(fn (string $scope): bool => ! $allowedScopes->contains($scope));

            if ($missing) {
                return $this->unavailable('agent_api_scope_missing:'.$missing, $agent, $availability['model']);
            }
        }

        return $availability;
    }

    /**
     * @return array{available: false, reason: string, agent: AiAgent|null, model: string|null}
     */
    private function unavailable(string $reason, ?AiAgent $agent = null, ?string $model = null): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'agent' => $agent,
            'model' => $model,
        ];
    }
}
