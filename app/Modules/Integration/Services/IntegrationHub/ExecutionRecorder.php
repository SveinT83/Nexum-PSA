<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\IntegrationHubExecution;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExecutionRecorder
{
    /** @param array<string, mixed> $summary @return array{execution:IntegrationHubExecution,reused:bool} */
    public function begin(Request $request, array $summary = []): array
    {
        $claims = (array) $request->attributes->get('integration_hub_claims', []);
        $scope = (array) ($claims['scope'] ?? []);
        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if (strlen($idempotencyKey) > 191) {
            throw new IntegrationHubDeniedException('idempotency_key_invalid', 'Idempotency key is too long.', 422, 'failed');
        }
        $digest = $idempotencyKey === '' ? null : hash('sha256', implode('|', [
            (string) config('integration-hub.installation_key'),
            (string) ($claims['capability']['key'] ?? ''),
            (string) ($claims['capability']['version'] ?? ''),
            (string) ($claims['actor']['id'] ?? ''),
            json_encode($scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $idempotencyKey,
        ]));

        try {
            return DB::transaction(function () use ($request, $claims, $scope, $summary, $idempotencyKey, $digest): array {
                if ($digest) {
                    $existing = IntegrationHubExecution::query()->where('idempotency_digest', $digest)->lockForUpdate()->first();
                    if ($existing) {
                        $request->attributes->set('integration_hub_execution_id', $existing->id);

                        return ['execution' => $existing, 'reused' => true];
                    }
                }

                $execution = IntegrationHubExecution::query()->create([
                    'correlation_id' => (string) $request->attributes->get('integration_hub_correlation_id'),
                    'installation_key' => (string) config('integration-hub.installation_key'),
                    'actor_id' => $claims['actor']['id'] ?? null,
                    'workload_id' => $claims['workload_id'] ?? null,
                    'service_actor_id' => $request->user()?->getAuthIdentifier(),
                    'client_id' => count($scope['client_ids'] ?? []) === 1 ? (int) $scope['client_ids'][0] : null,
                    'client_site_id' => count($scope['site_ids'] ?? []) === 1 ? (int) $scope['site_ids'][0] : null,
                    'integration_id' => count($scope['integration_ids'] ?? []) === 1 ? (string) $scope['integration_ids'][0] : null,
                    'capability_key' => $claims['capability']['key'] ?? 'unknown',
                    'capability_version' => $claims['capability']['version'] ?? 'unknown',
                    'environment' => $scope['environment'] ?? 'unknown',
                    'target_type' => $summary['target_type'] ?? null,
                    'target_id' => isset($summary['target_id']) ? (string) $summary['target_id'] : null,
                    'request_summary' => $this->sanitizeSummary($summary),
                    'policy_digest' => (string) ($claims['policy_digest'] ?? hash('sha256', 'unknown')),
                    'idempotency_key' => $idempotencyKey === '' ? null : hash('sha256', $idempotencyKey),
                    'idempotency_digest' => $digest,
                    'status' => 'running',
                    'started_at' => now(),
                    'retain_until' => now()->addDays(max(1, (int) config('integration-hub.audit_retention_days', 90))),
                ]);
                $request->attributes->set('integration_hub_execution_id', $execution->id);

                return ['execution' => $execution, 'reused' => false];
            });
        } catch (QueryException $exception) {
            $existing = $digest ? IntegrationHubExecution::query()->where('idempotency_digest', $digest)->first() : null;
            if (! $existing) {
                throw $exception;
            }
            $request->attributes->set('integration_hub_execution_id', $existing->id);

            return ['execution' => $existing, 'reused' => true];
        }
    }

    /** @param array<string, mixed>|null $checkpoint */
    public function step(IntegrationHubExecution $execution, int $sequence, string $key, string $status, ?array $checkpoint = null, ?string $failureCode = null): void
    {
        $execution->steps()->updateOrCreate(['sequence' => $sequence], [
            'step_key' => $key,
            'status' => $status,
            'checkpoint' => $checkpoint ? $this->sanitizeSummary($checkpoint) : null,
            'failure_code' => $failureCode,
            'started_at' => $status === 'running' ? now() : null,
            'finished_at' => in_array($status, ['completed', 'failed', 'partial', 'unknown', 'cancelled'], true) ? now() : null,
        ]);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $verification */
    public function complete(IntegrationHubExecution $execution, string $resultStatus, array $data, array $verification = [], ?string $failureCode = null): void
    {
        $state = match ($resultStatus) {
            'ok' => 'completed',
            'partial', 'stale' => 'partial',
            'unknown' => 'unknown',
            default => 'failed',
        };
        $execution->forceFill([
            'status' => $state,
            'result_status' => $resultStatus,
            'failure_code' => $failureCode,
            'outcome_summary' => $this->sanitizeSummary($data),
            'verification' => $this->sanitizeSummary($verification),
            'finished_at' => now(),
        ])->save();
    }

    public function cancel(IntegrationHubExecution $execution, string $reasonCode): void
    {
        if (! in_array($execution->status, ['queued', 'running', 'input_required'], true)) {
            throw new IntegrationHubDeniedException('execution_not_cancellable', 'Execution can no longer be cancelled.', 409, 'failed');
        }
        $execution->forceFill([
            'status' => 'cancelled',
            'result_status' => 'unknown',
            'failure_code' => $reasonCode,
            'cancelled_at' => now(),
            'finished_at' => now(),
            'verification' => ['rollback_claimed' => false],
        ])->save();
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function sanitizeSummary(array $values): array
    {
        $blocked = '/token|secret|password|authorization|credential|private.?key|cookie/i';
        $walk = function (array $input) use (&$walk, $blocked): array {
            $safe = [];
            foreach ($input as $key => $value) {
                $name = (string) $key;
                if (preg_match($blocked, $name)) {
                    continue;
                }
                if (is_array($value)) {
                    $safe[$name] = $walk($value);
                } elseif (is_scalar($value) || $value === null) {
                    $safe[$name] = is_string($value) ? mb_substr($value, 0, 500) : $value;
                }
            }

            return $safe;
        };

        return $walk($values);
    }
}
