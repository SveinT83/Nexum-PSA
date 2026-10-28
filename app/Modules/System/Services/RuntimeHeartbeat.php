<?php

namespace App\Modules\System\Services;

use App\Modules\System\Jobs\CompleteRuntimeQueueProbe;
use App\Modules\System\Support\RuntimeHealthScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Evidence writes are separate from observation and never consume business work. */
final class RuntimeHeartbeat
{
    public const TABLE = 'system_runtime_heartbeats';

    public function __construct(private readonly RuntimeHealthScope $scope) {}

    public function tick(): array
    {
        if (! config('runtime_health.enabled', false)) {
            return ['status' => 'blocked', 'reason_code' => 'runtime_health_disabled'];
        }

        try {
            $scope = $this->scope->get();
            $at = CarbonImmutable::now('UTC');
            $this->ensure($scope, null);
            DB::table(self::TABLE)->where('component_key', $this->scope->key($scope))
                ->where(fn ($q) => $q->whereNull('observed_at')->orWhere('observed_at', '<', $at))
                ->update(['observed_at' => $at]);

            $dispatched = 0;
            foreach ($scope['required_queues'] as $queue) {
                $this->ensure($scope, $queue);
                if (! in_array($queue, $scope['probe_queues'], true)
                    || in_array($queue, $scope['blocked_queues'], true)
                    || ! in_array($scope['driver'], ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                    continue;
                }
                $dispatched += $this->probe($scope, $queue, $at) ? 1 : 0;
            }

            // Only obsolete completed evidence expires; unresolved probes remain for reconciliation.
            $days = max(1, min(365, (int) config('runtime_health.retention_days', 30)));
            DB::table(self::TABLE)->whereNull('pending_probe_id')
                ->where('observed_at', '<', $at->subDays($days))->delete();

            return ['status' => 'recorded', 'probes_dispatched' => $dispatched];
        } catch (Throwable) {
            // SQL/transport errors can contain connection secrets; they are never diagnostic output.
            return ['status' => 'unknown', 'reason_code' => 'runtime_evidence_unavailable'];
        }
    }

    public function complete(string $runtime, string $component, string $probe): bool
    {
        if (! config('runtime_health.enabled', false)) {
            return false;
        }

        try {
            $scope = $this->scope->get();
            if ($scope['runtime_key'] !== $runtime) {
                return false;
            }
            $queue = collect($scope['probe_queues'])->first(
                fn ($name) => $this->scope->key($scope, $name) === $component,
            );
            if ($queue === null || in_array($queue, $scope['blocked_queues'], true)) {
                return false;
            }
            $at = CarbonImmutable::now('UTC');
            $current = DB::table(self::TABLE)->where('component_key', $component)
                ->where('runtime_key', $runtime)->where('pending_probe_id', $probe)->first();
            if ($current === null || $current->pending_since === null) {
                return false;
            }
            $scheduledAt = CarbonImmutable::parse($current->pending_since, 'UTC');
            if ($scheduledAt->isAfter($at)) {
                return false;
            }
            $latency = max(0, $at->getTimestamp() - $scheduledAt->getTimestamp());

            return DB::table(self::TABLE)->where('component_key', $component)
                ->where('runtime_key', $runtime)->where('pending_probe_id', $probe)
                ->where('pending_since', '<=', $at)
                ->update([
                    'completed_probe_id' => $probe, 'completed_at' => $at,
                    'completed_latency_seconds' => $latency,
                    'pending_probe_id' => null, 'pending_since' => null,
                    'enqueued_at' => null, 'blocked_reason' => null,
                ]) === 1;
        } catch (Throwable) {
            throw new RuntimeException('Runtime queue probe evidence is unavailable.');
        }
    }

    /**
     * Operator attestation after transport/failed-job reconciliation. This never marks
     * delivery, deletes a broker job, or republishes the old identity.
     */
    public function reconcile(string $queue, string $expectedProbe): bool
    {
        if (! config('runtime_health.enabled', false) || ! Str::isUuid($expectedProbe)) {
            return false;
        }
        try {
            $scope = $this->scope->get();
            if (! in_array($queue, $scope['probe_queues'], true)) {
                return false;
            }

            return DB::table(self::TABLE)->where('component_key', $this->scope->key($scope, $queue))
                ->where('pending_probe_id', $expectedProbe)
                ->update([
                    'pending_probe_id' => null, 'pending_since' => null, 'enqueued_at' => null,
                    'blocked_reason' => null, 'last_reconciled_probe_id' => $expectedProbe,
                    'last_reconciled_at' => CarbonImmutable::now('UTC'),
                ]) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function ensure(array $scope, ?string $queue): void
    {
        DB::table(self::TABLE)->insertOrIgnore([
            'component_key' => $this->scope->key($scope, $queue),
            'runtime_key' => $scope['runtime_key'],
            'kind' => $queue === null ? 'scheduler' : 'queue',
            'connection_name' => $queue === null ? null : $scope['connection'],
            'driver' => $queue === null ? null : $scope['driver'],
            'queue_name' => $queue,
        ]);
    }

    private function probe(array $scope, string $queue, CarbonImmutable $at): bool
    {
        $key = $this->scope->key($scope, $queue);
        $probe = (string) Str::uuid();
        // Conditional SQL claim, not an expiring cache lock: one outstanding probe even on a dead queue.
        $claimed = DB::table(self::TABLE)->where('component_key', $key)
            ->whereNull('pending_probe_id')
            ->where(fn ($q) => $q->whereNull('observed_at')
                ->orWhere('observed_at', '<=', $at->subSeconds($scope['probe_interval_seconds'])))
            ->update([
                'observed_at' => $at, 'pending_probe_id' => $probe, 'pending_since' => $at,
                'enqueued_at' => null, 'blocked_reason' => null,
            ]);
        if ($claimed !== 1) {
            return false;
        }

        try {
            // Claim commits before publishing. A crash/ambiguous transport never causes automatic replay.
            Queue::connection($scope['connection'])->push(
                new CompleteRuntimeQueueProbe($scope['runtime_key'], $key, $probe), '', $queue,
            );
            DB::table(self::TABLE)->where('component_key', $key)->where('pending_probe_id', $probe)
                ->update(['enqueued_at' => $at]);

            return true;
        } catch (Throwable) {
            DB::table(self::TABLE)->where('component_key', $key)->where('pending_probe_id', $probe)
                ->update(['blocked_reason' => 'probe_dispatch_unknown']);

            return false;
        }
    }
}
