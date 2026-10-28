<?php

namespace App\Modules\Notification\Support;

use App\Modules\Notification\Contracts\WebPushQueueEvidence;
use App\Modules\System\Queries\RuntimeHealthQuery;
use App\Modules\System\Support\RuntimeHealthScope;

/** Consume System-owned health and allowlists for the queue actually used by existing Notification jobs. */
final class SystemWebPushQueueEvidence implements WebPushQueueEvidence
{
    public function get(): array
    {
        $result = ['state' => 'unknown', 'reason_code' => 'queue_evidence_unavailable', 'connection' => null,
            'queue' => 'default', 'driver' => null, 'scope_hash' => null, 'last_completed_at' => null];
        try {
            if (! class_exists(RuntimeHealthQuery::class) || ! class_exists(RuntimeHealthScope::class)) {
                return array_replace($result, ['state' => 'blocked', 'reason_code' => 'runtime_health_integration_unavailable']);
            }
            if (! config('runtime_health.enabled', false)) {
                return array_replace($result, ['state' => 'blocked', 'reason_code' => 'runtime_health_not_enabled']);
            }
            $observed = app(RuntimeHealthQuery::class)->get();
            $scope = app(RuntimeHealthScope::class)->get();
            $connection = config('queue.default');
            if ($scope['connection'] !== $connection || ! in_array('default', $scope['required_queues'], true)
                || ! in_array('default', $scope['probe_queues'], true) || in_array('default', $scope['blocked_queues'], true)) {
                return array_replace($result, ['state' => 'blocked', 'reason_code' => 'default_queue_not_approved']);
            }
            if (! in_array($scope['driver'], ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                return array_replace($result, ['state' => 'blocked', 'reason_code' => 'asynchronous_queue_required']);
            }
            $component = collect($observed['components'] ?? [])->first(fn ($c) => ($c['kind'] ?? null) === 'queue'
                && ($c['queue'] ?? null) === 'default' && ($c['connection'] ?? null) === $connection);
            $state = in_array($component['status'] ?? null, ['healthy', 'stale', 'unknown', 'blocked'], true)
                ? $component['status'] : 'unknown';
            $reason = $component['reason_code'] ?? 'queue_evidence_missing';
            if (! is_string($reason) || ! preg_match('/\A[a-z0-9_]{1,80}\z/D', $reason)) {
                $reason = 'queue_evidence_invalid';
            }

            return ['state' => $state, 'reason_code' => $reason, 'connection' => $connection, 'queue' => 'default',
                'driver' => $scope['driver'], 'scope_hash' => hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR)),
                'last_completed_at' => $component['last_completed_at'] ?? null];
        } catch (\Throwable) {
            return $result;
        }
    }
}
