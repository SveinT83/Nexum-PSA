<?php

// Explicit deployment-owned scope: discovery never enables a queue or starts a worker.
$list = static fn (string $value): array => array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));

return [
    'enabled' => (bool) env('RUNTIME_HEALTH_ENABLED', false),
    'runtime_key' => env('RUNTIME_HEALTH_KEY', 'main'),
    'connection' => env('RUNTIME_HEALTH_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'required_queues' => $list((string) env('RUNTIME_HEALTH_REQUIRED_QUEUES', '')),
    'probe_queues' => $list((string) env('RUNTIME_HEALTH_PROBE_QUEUES', '')),
    'blocked_queues' => $list((string) env('RUNTIME_HEALTH_BLOCKED_QUEUES', '')),
    'scheduler_stale_seconds' => (int) env('RUNTIME_HEALTH_SCHEDULER_STALE_SECONDS', 180),
    'worker_stale_seconds' => (int) env('RUNTIME_HEALTH_WORKER_STALE_SECONDS', 300),
    // Optional per-owned-queue deadlines, kept inside the reviewed queue scope.
    'queue_stale_seconds' => [],
    'probe_interval_seconds' => 60,
    'retention_days' => 30,
];
