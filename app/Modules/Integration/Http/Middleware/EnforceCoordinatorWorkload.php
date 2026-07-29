<?php

namespace App\Modules\Integration\Http\Middleware;

use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Services\AiAccessAudit;
use App\Modules\Integration\Services\AiDataEgressPolicyEvaluator;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class EnforceCoordinatorWorkload
{
    public function __construct(
        private AiDataEgressPolicyEvaluator $evaluator,
        private ApiAbilityCatalog $abilityCatalog,
        private AiAccessAudit $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $profile, string $requiredAbility): Response
    {
        $startedAt = hrtime(true);
        $request->attributes->set('coordinator_request_id', (string) Str::uuid());
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $this->deny($request, null, $profile, 'workload_token_required', Response::HTTP_FORBIDDEN, $startedAt);
        }

        $binding = AiWorkloadTokenBinding::query()->with('workload')->where('personal_access_token_id', $token->id)->first();
        if (! $binding) {
            return $this->deny($request, null, $profile, 'workload_token_unbound', Response::HTTP_FORBIDDEN, $startedAt);
        }

        if (! $binding->isUsable()) {
            return $this->deny($request, $binding, $profile, 'workload_token_expired_or_revoked', Response::HTTP_FORBIDDEN, $startedAt);
        }

        $abilities = $token->abilities ?? [];
        if (in_array(ApiAbilityCatalog::FULL_ACCESS, $abilities, true)
            || collect($abilities)->contains(fn (string $ability): bool => ! $this->abilityCatalog->isReadOnly($ability))) {
            return $this->deny($request, $binding, $profile, 'workload_token_has_broad_or_write_scope', Response::HTTP_FORBIDDEN, $startedAt);
        }

        if (! in_array($requiredAbility, $abilities, true) || ! $binding->workload->allowsAbility($requiredAbility)) {
            return $this->deny($request, $binding, $profile, 'required_scope_missing', Response::HTTP_FORBIDDEN, $startedAt);
        }

        if (! $this->networkAllowed((string) $request->ip(), $binding->allowed_networks ?? [])) {
            return $this->deny($request, $binding, $profile, 'network_not_allowed', Response::HTTP_FORBIDDEN, $startedAt);
        }

        $installation = AiDataEgressPolicy::installation();
        $governance = null;
        if ($binding->workload->processing_mode !== 'local_only') {
            $modelPolicy = AiModelGovernancePolicy::query()
                ->where('ai_provider_id', $binding->workload->ai_provider_id)
                ->where('model', $binding->workload->model)
                ->first();
            if (! $modelPolicy || ! $modelPolicy->is_approved || $modelPolicy->expires_at?->isPast()
                || $modelPolicy->processing_mode !== $binding->workload->processing_mode
                || ! $this->evaluator->profileFits($profile, $modelPolicy->maximum_data_profile)) {
                return $this->deny($request, $binding, $profile, 'workload_model_not_approved', Response::HTTP_FORBIDDEN, $startedAt);
            }
            $governance = AiProviderGovernanceProfile::query()
                ->where('ai_provider_id', $binding->workload->ai_provider_id)->first();
        }
        $decision = $this->evaluator->evaluate(
            installation: $installation,
            processingMode: $binding->workload->processing_mode,
            dataProfile: $profile,
            governance: $governance,
            workload: $binding->workload,
        );
        if (! $decision->allowed) {
            return $this->deny($request, $binding, $profile, $decision->reasonCode, Response::HTTP_FORBIDDEN, $startedAt);
        }

        $limit = min($installation->requests_per_minute, $binding->requests_per_minute);
        $rateKey = 'coordinator-workload:'.$binding->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            return $this->deny($request, $binding, $profile, 'request_rate_exceeded', Response::HTTP_TOO_MANY_REQUESTS, $startedAt);
        }
        RateLimiter::hit($rateKey, 60);

        $request->attributes->set('coordinator_workload', $binding->workload);
        $request->attributes->set('coordinator_policy_limits', $decision->effectiveLimits);

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->audit->record($request, $binding, $profile, 'denied', 'request_failed', 500, $this->duration($startedAt));
            throw $exception;
        }

        $status = $response->getStatusCode();
        $this->audit->record(
            $request,
            $binding,
            $profile,
            $status < 400 ? 'allowed' : 'denied',
            $status < 400 ? 'allowed' : 'downstream_rejected',
            $status,
            $this->duration($startedAt),
            $this->audit->resultCount($response),
        );

        return $response;
    }

    private function deny(
        Request $request,
        ?AiWorkloadTokenBinding $binding,
        string $profile,
        string $reason,
        int $status,
        int $startedAt,
    ): JsonResponse {
        $event = $this->audit->record($request, $binding, $profile, 'denied', $reason, $status, $this->duration($startedAt));

        return response()->json([
            'message' => 'Coordinator access denied.',
            'reason_code' => $reason,
            'request_id' => $event->request_id,
        ], $status);
    }

    private function networkAllowed(string $ip, array $allowedNetworks): bool
    {
        return $allowedNetworks === [] || IpUtils::checkIp($ip, $allowedNetworks);
    }

    private function duration(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
