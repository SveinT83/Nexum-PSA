<?php

namespace App\Modules\Integration\Support;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class IntegrationHubResult
{
    public const CONTRACT_NAME = 'nexum.integration-hub.result';

    public const CONTRACT_VERSION = '1.0';

    public const STATUSES = ['ok', 'denied', 'unavailable', 'failed', 'unknown', 'stale', 'partial'];

    /** @param array<string, mixed> $options */
    public static function payload(string $status, mixed $data, array $options = []): array
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unsupported Integration Hub result status.');
        }

        $observedAt = $options['observed_at'] ?? null;
        if ($observedAt instanceof CarbonInterface) {
            $observedAt = $observedAt->toIso8601String();
        }

        $staleAfter = isset($options['stale_after_seconds']) ? (int) $options['stale_after_seconds'] : null;

        return [
            'contract' => [
                'name' => self::CONTRACT_NAME,
                'version' => self::CONTRACT_VERSION,
            ],
            'status' => $status,
            'correlation_id' => (string) ($options['correlation_id'] ?? Str::uuid()),
            'capability' => [
                'key' => $options['capability_key'] ?? null,
                'version' => $options['capability_version'] ?? null,
            ],
            'source' => [
                'type' => $options['source_type'] ?? 'nexum',
                'name' => $options['source_name'] ?? 'nexum',
            ],
            'freshness' => [
                'observed_at' => $observedAt,
                'stale_after_seconds' => $staleAfter,
                'is_stale' => $status === 'stale',
            ],
            'scope' => $options['scope'] ?? ['installation' => config('integration-hub.installation_key')],
            'data' => $data,
            'reason' => isset($options['reason_code']) ? [
                'code' => $options['reason_code'],
                'message' => $options['reason_message'] ?? null,
                'retryable' => (bool) ($options['retryable'] ?? false),
            ] : null,
            'meta' => $options['meta'] ?? null,
        ];
    }

    /** @param array<string, mixed> $options */
    public static function response(string $status, mixed $data, array $options = [], int $httpStatus = 200): JsonResponse
    {
        return response()->json(self::payload($status, $data, $options), $httpStatus);
    }
}
