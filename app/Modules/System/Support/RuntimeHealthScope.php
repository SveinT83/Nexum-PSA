<?php

namespace App\Modules\System\Support;

use InvalidArgumentException;

/** Validate operator-owned scope before it can create evidence or address a broker. */
final class RuntimeHealthScope
{
    public function get(): array
    {
        $runtime = $this->name(config('runtime_health.runtime_key'), 64);
        $connection = $this->name(config('runtime_health.connection'), 64);
        $required = $this->queues(config('runtime_health.required_queues', []));
        $probes = $this->queues(config('runtime_health.probe_queues', []));
        $blocked = $this->queues(config('runtime_health.blocked_queues', []));
        if (array_diff($probes, $required) !== [] || array_diff($blocked, $required) !== []) {
            throw new InvalidArgumentException('Runtime health queue scope is invalid.');
        }

        $deadlines = config('runtime_health.queue_stale_seconds', []);
        if (! is_array($deadlines) || count($deadlines) > 32 || array_diff(array_keys($deadlines), $required) !== []) {
            throw new InvalidArgumentException('Runtime health deadlines are outside the owned scope.');
        }
        foreach ($deadlines as $queue => $seconds) {
            if (! is_int($seconds) || $seconds < 60 || $seconds > 3600) {
                throw new InvalidArgumentException('Runtime health deadline is invalid.');
            }
        }

        return [
            'queue_stale_seconds' => $deadlines,
            'runtime_key' => $runtime,
            'connection' => $connection,
            'driver' => config('queue.connections.'.$connection.'.driver'),
            'required_queues' => $required,
            'probe_queues' => $probes,
            'blocked_queues' => $blocked,
            'scheduler_stale_seconds' => max(60, min(3600, (int) config('runtime_health.scheduler_stale_seconds', 180))),
            'worker_stale_seconds' => max(60, min(3600, (int) config('runtime_health.worker_stale_seconds', 300))),
            'probe_interval_seconds' => max(60, min(3600, (int) config('runtime_health.probe_interval_seconds', 60))),
        ];
    }

    public function key(array $scope, ?string $queue = null): string
    {
        return hash('sha256', json_encode([
            $scope['runtime_key'], $queue === null ? 'scheduler' : 'queue',
            $queue === null ? null : $scope['connection'],
            $queue === null ? null : $scope['driver'], $queue,
        ], JSON_THROW_ON_ERROR));
    }

    private function queues(mixed $value): array
    {
        if (! is_array($value) || count($value) > 32) {
            throw new InvalidArgumentException('Runtime health queue scope is invalid.');
        }

        return array_values(array_unique(array_map(fn ($queue) => $this->name($queue, 80), $value)));
    }

    private function name(mixed $value, int $limit): string
    {
        if (! is_string($value) || strlen($value) > $limit || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/D', $value)) {
            throw new InvalidArgumentException('Runtime health component name is invalid.');
        }

        return $value;
    }
}
