<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Queries\PurchaseOrderImportOperationalHealthQuery;
use Carbon\CarbonImmutable;

class RunSupplierOrderImportOperationsMaintenance
{
    /** @var list<string> */
    private const HEALTH_ALERT_TYPES = [
        'stale_processing',
        'stale_backlog',
        'scheduler_unhealthy',
        'queue_worker_unhealthy',
        'circuit_breaker',
        'repeated_failure',
        'ai_policy_denial',
        'automation_actor_disabled',
    ];

    public function __construct(
        private readonly RecoverStaleSupplierOrderImports $recoverStale,
        private readonly PurchaseOrderImportOperationalHealthQuery $healthQuery,
        private readonly PublishSupplierOrderImportOperationalAlert $alerts,
        private readonly UpdateSupplierOrderImportOperationalState $operationalState,
    ) {}

    /** @return array{health: array<string, mixed>, recovered_count: int, active_alert_count: int} */
    public function handle(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $this->alerts->retryFailedDeliveries();
        $initialHealth = $this->healthQuery->get($at);
        $staleMinutes = (int) data_get($initialHealth, 'thresholds.processing_stale_minutes', 15);
        $recovered = $this->recoverStale->handle($staleMinutes, $at);
        $activeIdentities = [];

        foreach ($recovered as $facts) {
            $identity = 'stale_processing:'.$facts['import_id'];
            $activeIdentities[] = $identity;
            $this->alerts->handle([
                'identity' => $identity,
                'type' => 'stale_processing',
                'severity' => 'critical',
                'import_id' => $facts['import_id'],
                'reason_code' => 'stale_processing_recovered',
                'title' => 'Stale import worker lock recovered',
                'summary' => 'A stale supplier-order import lock was released and scheduled for a bounded retry.',
                'context' => $facts,
            ]);
        }

        $policy = PurchaseOrderAutomationPolicy::query()
            ->with('automationUser')
            ->where('is_current', true)
            ->first();
        $runtimeActive = $policy && $policy->runtime_mode !== PurchaseOrderAutomationPolicy::MODE_OFF;
        $health = $this->healthQuery->get($at);
        if ($runtimeActive && ! data_get($health, 'scheduler.healthy', false)) {
            $this->publish($activeIdentities, [
                'identity' => 'scheduler_unhealthy',
                'type' => 'scheduler_unhealthy',
                'severity' => 'critical',
                'reason_code' => 'scheduler_heartbeat_stale',
                'title' => 'Supplier-order scheduler heartbeat is stale',
                'summary' => 'The supplier-order scheduler heartbeat is missing or stale. New imports may be delayed.',
                'context' => ['scheduler' => data_get($health, 'scheduler')],
            ]);
        }
        if ($runtimeActive && ! data_get($health, 'queue_worker.healthy', false)) {
            $this->publish($activeIdentities, [
                'identity' => 'queue_worker_unhealthy',
                'type' => 'queue_worker_unhealthy',
                'severity' => 'critical',
                'reason_code' => 'queue_worker_heartbeat_stale',
                'title' => 'Supplier-order queue worker is unhealthy',
                'summary' => 'The worker heartbeat or queue-latency sample is stale. Imports remain durable but may be delayed.',
                'context' => ['queue_worker' => data_get($health, 'queue_worker')],
            ]);
        }

        $backlogThresholdSeconds = ((int) data_get($health, 'thresholds.backlog_stale_minutes', 15)) * 60;
        if ($runtimeActive && (int) data_get($health, 'imports.backlog_age_seconds', 0) > $backlogThresholdSeconds) {
            $this->publish($activeIdentities, [
                'identity' => 'stale_backlog',
                'type' => 'stale_backlog',
                'severity' => 'warning',
                'reason_code' => 'stale_import_backlog',
                'title' => 'Supplier-order import backlog is stale',
                'summary' => 'One or more pending or due-retry imports have exceeded the configured backlog age.',
                'context' => [
                    'oldest_actionable_at' => data_get($health, 'imports.oldest_actionable_at'),
                    'backlog_age_seconds' => data_get($health, 'imports.backlog_age_seconds'),
                ],
            ]);
        }

        if ($policy && in_array($policy->runtime_mode, [
            PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
            PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI,
        ], true)) {
            $actor = $policy->automationUser;
            if (! SupplierOrderAutomationActor::canAct($actor, 'storage.purchase_manage')) {
                $this->publish($activeIdentities, [
                    'identity' => 'automation_actor_disabled',
                    'type' => 'automation_actor_disabled',
                    'severity' => 'critical',
                    'reason_code' => 'automation_actor_unavailable',
                    'title' => 'Supplier-order automation authority is unavailable',
                    'summary' => 'The protected Nexum supplier-order automation identity could not be resolved.',
                    'context' => ['automation_user_id' => $policy->automation_user_id],
                ]);
            }
        }

        PurchaseOrderImportProfile::query()
            ->where('lifecycle_state', PurchaseOrderImportProfile::STATE_PAUSED)
            ->where('pause_reason', 'like', 'circuit_breaker:%')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'name', 'consecutive_failures', 'pause_reason'])
            ->each(function (PurchaseOrderImportProfile $profile) use (&$activeIdentities): void {
                $this->publish($activeIdentities, [
                    'identity' => 'circuit_breaker:'.$profile->id,
                    'type' => 'circuit_breaker',
                    'severity' => 'critical',
                    'profile_id' => $profile->id,
                    'reason_code' => 'profile_circuit_breaker_open',
                    'title' => 'Supplier profile circuit breaker is open',
                    'summary' => 'Profile '.$profile->name.' is paused after repeated unsafe or invalid results.',
                    'context' => [
                        'profile_id' => $profile->id,
                        'consecutive_failures' => $profile->consecutive_failures,
                        'pause_reason' => $profile->pause_reason,
                    ],
                ]);
            });

        PurchaseOrderImport::query()
            ->where('status', PurchaseOrderImport::STATUS_FAILED)
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'attempt_count', 'reason_code'])
            ->each(function (PurchaseOrderImport $import) use (&$activeIdentities): void {
                $this->publish($activeIdentities, [
                    'identity' => 'repeated_failure:'.$import->id,
                    'type' => 'repeated_failure',
                    'severity' => 'critical',
                    'import_id' => $import->id,
                    'reason_code' => $import->reason_code ?: 'import_failed',
                    'title' => 'Supplier-order import retries are exhausted',
                    'summary' => 'An import stopped after bounded retry handling and requires attention.',
                    'context' => [
                        'import_id' => $import->id,
                        'attempt_count' => $import->attempt_count,
                        'reason_code' => $import->reason_code,
                    ],
                ]);
            });

        PurchaseOrderImport::query()
            ->whereIn('status', [PurchaseOrderImport::STATUS_NEEDS_ATTENTION, PurchaseOrderImport::STATUS_FAILED])
            ->where('reason_context->ai->status', 'denied')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'reason_code'])
            ->each(function (PurchaseOrderImport $import) use (&$activeIdentities): void {
                $this->publish($activeIdentities, [
                    'identity' => 'ai_policy_denial:'.$import->id,
                    'type' => 'ai_policy_denial',
                    'severity' => 'warning',
                    'import_id' => $import->id,
                    'reason_code' => $import->reason_code,
                    'title' => 'AI policy denied supplier-order extraction',
                    'summary' => 'Integration governance denied an AI extraction attempt. No ungoverned fallback was used.',
                    'context' => ['import_id' => $import->id, 'reason_code' => $import->reason_code],
                ]);
            });

        $this->alerts->resolveMissing(self::HEALTH_ALERT_TYPES, $activeIdentities);
        $health = $this->healthQuery->get($at);
        $this->operationalState->handle([
            'last_health_check_at' => $at,
            'last_maintenance_at' => $at,
            'last_recovered_import_count' => count($recovered),
            'health_state' => $health['state'],
            'health_snapshot' => $health,
        ]);

        return [
            'health' => $health,
            'recovered_count' => count($recovered),
            'active_alert_count' => count($activeIdentities),
        ];
    }

    /** @param list<string> $identities @param array<string, mixed> $alert */
    private function publish(array &$identities, array $alert): void
    {
        $identities[] = (string) $alert['identity'];
        $this->alerts->handle($alert);
    }
}
