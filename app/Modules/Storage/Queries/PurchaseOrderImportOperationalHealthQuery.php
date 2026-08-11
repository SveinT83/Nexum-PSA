<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Storage\Actions\UpdateSupplierOrderImportOperationalState;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PurchaseOrderImportOperationalHealthQuery
{
    /**
     * Return live scheduler, worker, backlog, and exception health for Admin/UI consumers.
     *
     * @return array<string, mixed>
     */
    public function get(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->first();
        $runtimeMode = $policy?->runtime_mode ?? PurchaseOrderAutomationPolicy::MODE_OFF;
        $thresholds = $this->thresholds($policy);
        $operations = DB::table('storage_purchase_order_import_operations')
            ->where('operation_key', UpdateSupplierOrderImportOperationalState::OPERATION_KEY)
            ->first();

        $scheduler = $this->heartbeat(
            $operations?->scheduler_heartbeat_at,
            $thresholds['scheduler_stale_minutes'],
            $at,
        );
        $worker = $this->heartbeat(
            $operations?->worker_heartbeat_at,
            $thresholds['worker_stale_minutes'],
            $at,
        );
        $worker['queue_latency_seconds'] = $operations?->worker_queue_latency_seconds === null
            ? null
            : (int) $operations->worker_queue_latency_seconds;
        $worker['latency_healthy'] = $worker['queue_latency_seconds'] === null
            ? false
            : $worker['queue_latency_seconds'] <= ($thresholds['worker_stale_minutes'] * 60);
        $worker['healthy'] = $worker['healthy'] && $worker['latency_healthy'];

        $statusCounts = PurchaseOrderImport::query()
            ->select('status', DB::raw('COUNT(*) AS aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        $oldestPendingAt = PurchaseOrderImport::query()
            ->where('status', PurchaseOrderImport::STATUS_PENDING)
            ->min('created_at');
        $oldestRetryAt = PurchaseOrderImport::query()
            ->where('status', PurchaseOrderImport::STATUS_RETRY_SCHEDULED)
            ->where('next_retry_at', '<=', $at)
            ->min('next_retry_at');
        $oldestBacklogAt = collect([$oldestPendingAt, $oldestRetryAt])
            ->filter()
            ->sortBy(fn (mixed $value): int => CarbonImmutable::parse($value)->getTimestamp())
            ->first();
        $backlogAgeSeconds = $oldestBacklogAt
            ? max(0, (int) CarbonImmutable::parse($oldestBacklogAt)->diffInSeconds($at))
            : 0;
        $staleProcessingCount = PurchaseOrderImport::query()
            ->where('status', PurchaseOrderImport::STATUS_PROCESSING)
            ->where(function ($query) use ($at, $thresholds): void {
                $cutoff = $at->subMinutes($thresholds['processing_stale_minutes']);
                $query->where('locked_at', '<=', $cutoff)
                    ->orWhere(function ($missingLock) use ($cutoff): void {
                        $missingLock->whereNull('locked_at')->where('updated_at', '<=', $cutoff);
                    });
            })
            ->count();
        $pausedProfiles = PurchaseOrderImportProfile::query()
            ->where('lifecycle_state', PurchaseOrderImportProfile::STATE_PAUSED)
            ->count();
        $openAlerts = DB::table('storage_purchase_order_import_operational_alerts')
            ->whereNull('resolved_at')
            ->whereIn('severity', ['warning', 'critical'])
            ->count();
        $failedDeliveries = DB::table('storage_purchase_order_import_alert_deliveries')
            ->where('status', 'failed')
            ->count();

        $isActive = $runtimeMode !== PurchaseOrderAutomationPolicy::MODE_OFF;
        $state = 'healthy';
        if (! $isActive) {
            $state = 'disabled';
        } elseif (! $scheduler['healthy'] || ! $worker['healthy'] || $staleProcessingCount > 0) {
            $state = 'critical';
        } elseif (
            $backlogAgeSeconds > ($thresholds['backlog_stale_minutes'] * 60)
            || ($statusCounts[PurchaseOrderImport::STATUS_FAILED] ?? 0) > 0
            || ($statusCounts[PurchaseOrderImport::STATUS_NEEDS_ATTENTION] ?? 0) > 0
            || $pausedProfiles > 0
            || $openAlerts > 0
            || $failedDeliveries > 0
        ) {
            $state = 'warning';
        }

        return [
            'state' => $state,
            'runtime_mode' => $runtimeMode,
            'scheduler' => $scheduler,
            'queue_worker' => $worker,
            'imports' => [
                'status_counts' => $statusCounts,
                'oldest_actionable_at' => $oldestBacklogAt,
                'backlog_age_seconds' => $backlogAgeSeconds,
                'stale_processing_count' => $staleProcessingCount,
            ],
            'profiles' => ['paused_count' => $pausedProfiles],
            'alerts' => ['open_count' => $openAlerts],
            'notifications' => ['failed_delivery_count' => $failedDeliveries],
            'maintenance' => [
                'last_health_check_at' => $operations?->last_health_check_at,
                'last_maintenance_at' => $operations?->last_maintenance_at,
                'last_retention_at' => $operations?->last_retention_at,
                'last_digest_at' => $operations?->last_digest_at,
                'last_dispatch_completed_at' => $operations?->last_dispatch_completed_at,
            ],
            'thresholds' => $thresholds,
        ];
    }

    /** @return array{healthy: bool, last_heartbeat_at: ?string, age_seconds: ?int} */
    private function heartbeat(?string $value, int $staleMinutes, CarbonImmutable $at): array
    {
        if (! $value) {
            return ['healthy' => false, 'last_heartbeat_at' => null, 'age_seconds' => null];
        }

        $heartbeat = CarbonImmutable::parse($value);
        $age = max(0, (int) $heartbeat->diffInSeconds($at));

        return [
            'healthy' => $age <= ($staleMinutes * 60),
            'last_heartbeat_at' => $heartbeat->toIso8601String(),
            'age_seconds' => $age,
        ];
    }

    /**
     * Operational overrides are bounded and can only tune timing, never safety gates.
     *
     * @return array{scheduler_stale_minutes: int, worker_stale_minutes: int, processing_stale_minutes: int, backlog_stale_minutes: int}
     */
    private function thresholds(?PurchaseOrderAutomationPolicy $policy): array
    {
        $operations = is_array(data_get($policy?->advanced_rules, 'operations'))
            ? data_get($policy?->advanced_rules, 'operations')
            : [];

        return [
            'scheduler_stale_minutes' => $this->bounded($operations['scheduler_stale_minutes'] ?? 5, 2, 60),
            'worker_stale_minutes' => $this->bounded($operations['worker_stale_minutes'] ?? 5, 2, 60),
            'processing_stale_minutes' => $this->bounded($operations['processing_stale_minutes'] ?? 15, 5, 1440),
            'backlog_stale_minutes' => $this->bounded($operations['backlog_stale_minutes'] ?? 15, 5, 1440),
        ];
    }

    private function bounded(mixed $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) $value));
    }
}
