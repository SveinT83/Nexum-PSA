<?php

namespace App\Modules\System\Queries;

use App\Modules\System\Services\RuntimeHeartbeat;
use App\Modules\System\Support\RuntimeHealthScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Four-state read-only health; a successful scheduler dispatch is not worker completion. */
final class RuntimeHealthQuery
{
    public function __construct(private readonly RuntimeHealthScope $scope) {}

    public function get(): array
    {
        $at = CarbonImmutable::now('UTC');
        $result = [
            'observed_at' => $at->toIso8601String(),
            'status' => 'unknown',
            'reason_code' => 'runtime_evidence_unavailable',
            'components' => [],
            'coverage' => 'scheduler_tick_and_allowlisted_queue_probes_only',
            'business_delivery_verified' => false,
        ];
        if (! config('runtime_health.enabled', false)) {
            return array_replace($result, ['status' => 'blocked', 'reason_code' => 'runtime_health_disabled']);
        }

        try {
            $scope = $this->scope->get();
        } catch (Throwable) {
            return array_replace($result, ['reason_code' => 'runtime_scope_invalid']);
        }
        $result['runtime_key'] = $scope['runtime_key'];
        $result['thresholds'] = array_intersect_key($scope, array_flip([
            'scheduler_stale_seconds', 'worker_stale_seconds', 'probe_interval_seconds',
        ]));
        try {
            $rows = DB::table(RuntimeHeartbeat::TABLE)->where('runtime_key', $scope['runtime_key'])
                ->get()->keyBy('component_key');
        } catch (Throwable) {
            return $result;
        }

        $scheduler = $rows->get($this->scope->key($scope));
        $state = $this->freshness($scheduler?->observed_at, $at, $scope['scheduler_stale_seconds']);
        $result['components'][] = ['kind' => 'scheduler', 'last_observed_at' => $scheduler?->observed_at] + $state;
        foreach ($scope['required_queues'] as $queue) {
            $row = $rows->get($this->scope->key($scope, $queue));
            $deadline = $scope['queue_stale_seconds'][$queue] ?? $scope['worker_stale_seconds'];
            $component = [
                'threshold_seconds' => $deadline,
                'kind' => 'queue', 'connection' => $scope['connection'],
                'queue' => $queue, 'driver' => in_array($scope['driver'], ['database', 'redis', 'sqs', 'beanstalkd', 'sync', 'null'], true) ? $scope['driver'] : 'unsupported',
                'pending_probe_id' => $row?->pending_probe_id,
                'completed_probe_id' => $row?->completed_probe_id,
                'last_completed_at' => $row?->completed_at,
                'queue_latency_seconds' => isset($row->completed_latency_seconds) ? (int) $row->completed_latency_seconds : null,
                'pending_since' => $row?->pending_since,
            ];
            if (in_array($queue, $scope['blocked_queues'], true)) {
                $state = ['status' => 'blocked', 'reason_code' => 'operator_review_required'];
            } elseif (! in_array($scope['driver'], ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                $state = ['status' => 'unknown', 'reason_code' => 'asynchronous_backend_required'];
            } elseif (! in_array($queue, $scope['probe_queues'], true)) {
                $state = ['status' => 'blocked', 'reason_code' => 'queue_probe_not_approved'];
            } elseif ($row?->blocked_reason !== null) {
                $state = ['status' => 'blocked', 'reason_code' => 'probe_dispatch_unknown'];
            } elseif ($row?->pending_probe_id !== null && $row?->enqueued_at === null) {
                $state = ['status' => 'unknown', 'reason_code' => 'probe_dispatch_unconfirmed'];
            } else {
                $state = $this->freshness($row?->completed_at, $at, $deadline);
                if ($state['status'] === 'healthy' && $component['queue_latency_seconds'] === null) {
                    $state = ['status' => 'unknown', 'reason_code' => 'queue_latency_unknown'];
                } elseif ($state['status'] === 'healthy' && $component['queue_latency_seconds'] > $deadline) {
                    $state = ['status' => 'stale', 'reason_code' => 'queue_latency_exceeded'];
                }
                if ($row?->pending_probe_id !== null) {
                    $pending = $this->freshness($row->pending_since, $at, $deadline);
                    if ($pending['status'] !== 'healthy') {
                        $state = $pending;
                    }
                }
            }
            $result['components'][] = $component + $state;
        }
        if ($scope['required_queues'] === []) {
            return array_replace($result, ['reason_code' => 'required_queues_not_configured']);
        }

        // Missing evidence can never be concealed by other components reporting healthy.
        $states = array_column($result['components'], 'status');
        foreach (['blocked', 'unknown', 'stale', 'healthy'] as $status) {
            if (in_array($status, $states, true)) {
                $result['status'] = $status;
                $result['reason_code'] = $status === 'healthy' ? 'fresh_component_evidence' : 'component_'.$status;
                break;
            }
        }

        return $result;
    }

    private function freshness(?string $value, CarbonImmutable $at, int $threshold): array
    {
        if ($value === null) {
            return ['status' => 'unknown', 'reason_code' => 'evidence_missing', 'age_seconds' => null];
        }
        try {
            $stamp = CarbonImmutable::parse($value, 'UTC');
            $age = $at->getTimestamp() - $stamp->getTimestamp();
            if ($age < 0) {
                return ['status' => 'unknown', 'reason_code' => 'evidence_in_future', 'age_seconds' => null];
            }

            return [
                'status' => $age <= $threshold ? 'healthy' : 'stale',
                'reason_code' => $age <= $threshold ? 'fresh_evidence' : 'evidence_stale',
                'age_seconds' => $age,
            ];
        } catch (Throwable) {
            return ['status' => 'unknown', 'reason_code' => 'evidence_invalid', 'age_seconds' => null];
        }
    }
}
