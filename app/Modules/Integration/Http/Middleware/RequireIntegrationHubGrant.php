<?php

namespace App\Modules\Integration\Http\Middleware;

use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Services\IntegrationHub\CapabilityRegistry;
use App\Modules\Integration\Services\IntegrationHub\GrantVerifier;
use App\Modules\Integration\Services\IntegrationHub\HubAudit;
use App\Modules\Integration\Support\IntegrationHubResult;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequireIntegrationHubGrant
{
    public function __construct(
        private GrantVerifier $verifier,
        private HubAudit $audit,
        private CapabilityRegistry $registry,
    ) {}

    public function handle(Request $request, Closure $next, string $capability, string $version = '1.0'): Response
    {
        $startedAt = hrtime(true);
        $correlation = (string) $request->header('X-Correlation-ID');
        if (! Str::isUuid($correlation)) {
            $correlation = (string) Str::uuid();
        }
        $request->attributes->set('integration_hub_correlation_id', $correlation);
        $request->attributes->set('integration_hub_capability_key', $capability);
        $request->attributes->set('integration_hub_capability_version', $version);

        $requestedVersion = trim((string) $request->header('Accept-Capability-Version', $version));
        $compatible = $this->registry->findCompatible($capability, $requestedVersion);
        if (! $compatible || $compatible->contract_version !== $version) {
            $this->audit->record($request, 'denied', 'failed', 'contract_version_unsupported', 409, $this->duration($startedAt));

            return $this->versioned(IntegrationHubResult::response('failed', null, [
                'correlation_id' => $correlation,
                'capability_key' => $capability,
                'capability_version' => $version,
                'reason_code' => 'contract_version_unsupported',
                'reason_message' => 'Capability contract is unsupported.',
            ], 409), $version);
        }

        try {
            $verified = $this->verifier->verify($request, $capability, $version);
            $request->attributes->set('integration_hub_claims', $verified['claims']);
            $request->attributes->set('integration_hub_grant_record', $verified['record']);
            $request->attributes->set('integration_hub_delegated_actor', $verified['actor']);
            $request->attributes->set('integration_hub_workload', $verified['workload']);
            $request->attributes->set('integration_hub_capability', $verified['capability']);
            $request->attributes->set('integration_hub_correlation_id', $verified['claims']['correlation_id']);
        } catch (IntegrationHubDeniedException $exception) {
            $this->audit->record($request, 'denied', $exception->resultStatus, $exception->reasonCode, $exception->httpStatus, $this->duration($startedAt));

            return $this->versioned(IntegrationHubResult::response($exception->resultStatus, null, [
                'correlation_id' => $correlation,
                'capability_key' => $capability,
                'capability_version' => $version,
                'reason_code' => $exception->reasonCode,
                'reason_message' => $exception->getMessage(),
                'retryable' => $exception->retryable,
            ], $exception->httpStatus), $version);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->audit->record($request, 'allowed', 'failed', 'downstream_exception', 500, $this->duration($startedAt));
            throw $exception;
        }

        $payload = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
        if ($response->getStatusCode() === 422 && is_array($payload['errors'] ?? null)) {
            $fields = array_values(array_slice(array_keys($payload['errors']), 0, 25));
            $response = IntegrationHubResult::response('failed', null, [
                'correlation_id' => $this->correlation($request, $correlation),
                'capability_key' => $capability,
                'capability_version' => $version,
                'scope' => (array) (($request->attributes->get('integration_hub_claims', []))['scope'] ?? []),
                'reason_code' => 'request_validation_failed',
                'reason_message' => 'The request parameters are invalid.',
                'meta' => ['invalid_fields' => $fields],
            ], 422);
            $payload = (array) $response->getData(true);
        }
        $status = is_string($payload['status'] ?? null) ? $payload['status'] : ($response->getStatusCode() < 400 ? 'ok' : 'failed');
        $reason = is_string($payload['reason']['code'] ?? null) ? $payload['reason']['code'] : ($response->getStatusCode() < 400 ? 'allowed' : 'downstream_rejected');
        $this->audit->record($request, 'allowed', $status, $reason, $response->getStatusCode(), $this->duration($startedAt), [
            'source' => $payload['source']['name'] ?? 'nexum',
            'observed_at' => $payload['freshness']['observed_at'] ?? null,
            'freshness_status' => $status === 'stale' ? 'stale' : ($payload['freshness']['observed_at'] ?? null ? 'fresh' : 'unknown'),
            'result_count' => is_array($payload['data'] ?? null) ? count($payload['data']) : null,
            'filter_keys' => array_keys($request->query()),
            'page' => $request->integer('page', 1),
            'per_page' => $request->integer('per_page', 25),
        ]);

        return $this->versioned($response, $version);
    }

    private function correlation(Request $request, string $fallback): string
    {
        return (string) ($request->attributes->get('integration_hub_correlation_id') ?: $fallback);
    }

    private function varyHeader(?string $current): string
    {
        return collect(explode(',', (string) $current))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->push('Accept-Capability-Version')
            ->unique(fn (string $value): string => strtolower($value))
            ->implode(', ');
    }

    private function versioned(Response $response, string $version): Response
    {
        $response->headers->set('Content-Capability-Version', $version);
        $response->headers->set('Vary', $this->varyHeader($response->headers->get('Vary')));

        return $response;
    }

    private function duration(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
