<?php

namespace App\Modules\Integration\Http\Middleware;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Services\IntegrationHub\HubAudit;
use App\Modules\Integration\Support\IntegrationHubResult;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireIntegrationHubOperator
{
    public function __construct(private HubAudit $audit) {}

    public function handle(Request $request, Closure $next, string $ability, string $permission): Response
    {
        $startedAt = hrtime(true);
        $correlation = (string) $request->header('X-Correlation-ID');
        if (! Str::isUuid($correlation)) {
            $correlation = (string) Str::uuid();
        }
        $request->attributes->set('integration_hub_correlation_id', $correlation);

        try {
            $bearer = (string) $request->bearerToken();
            $token = $bearer !== '' ? PersonalAccessToken::findToken($bearer) : null;
            $actor = $token?->tokenable;
            if (! $actor instanceof User || ! $token instanceof PersonalAccessToken) {
                throw new IntegrationHubDeniedException('operator_token_required');
            }
            if ($token->expires_at?->isPast()) {
                throw new IntegrationHubDeniedException('operator_token_expired');
            }
            if (! $actor->isActive() || $actor->isSystemActor()) {
                throw new IntegrationHubDeniedException('operator_identity_invalid');
            }

            $abilities = array_values(array_map('strval', $token->abilities ?? []));
            if (in_array('*', $abilities, true)) {
                throw new IntegrationHubDeniedException('broad_token_rejected');
            }
            if (! in_array($ability, $abilities, true)) {
                throw new IntegrationHubDeniedException('operator_ability_missing');
            }
            if (! $actor->can($permission)) {
                throw new IntegrationHubDeniedException('operator_permission_missing');
            }

            $actor->withAccessToken($token);
            $request->setUserResolver(fn (): User => $actor);
            $request->attributes->set('integration_hub_actor_id', $actor->id);
        } catch (IntegrationHubDeniedException $exception) {
            $this->audit->record($request, 'denied', $exception->resultStatus, $exception->reasonCode, $exception->httpStatus, $this->duration($startedAt));

            return IntegrationHubResult::response($exception->resultStatus, null, [
                'correlation_id' => $correlation,
                'reason_code' => $exception->reasonCode,
                'reason_message' => $exception->getMessage(),
                'retryable' => $exception->retryable,
            ], $exception->httpStatus);
        }

        $response = $next($request);
        $this->audit->record(
            $request,
            $response->getStatusCode() < 400 ? 'allowed' : 'denied',
            $response->getStatusCode() < 400 ? 'ok' : 'failed',
            $response->getStatusCode() < 400 ? 'operator_request_allowed' : 'operator_request_rejected',
            $response->getStatusCode(),
            $this->duration($startedAt),
        );

        return $response;
    }

    private function duration(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
