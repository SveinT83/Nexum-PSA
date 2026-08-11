<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use Carbon\CarbonImmutable;

class PurgeSupplierOrderImportTroubleshootingData
{
    public function __construct(
        private readonly UpdateSupplierOrderImportOperationalState $operationalState,
    ) {}

    /**
     * Record the retention pass without mutating immutable import attempts or source evidence.
     * Optional attempt metadata remains part of the append-only audit record.
     *
     * @return array{retention_days: int, attempt_metadata_cleared: int, snapshot_fields_removed: int}
     */
    public function handle(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->first();
        $retentionDays = max(30, min(3650, (int) ($policy?->retention_days ?? 730)));
        $this->operationalState->handle([
            'last_retention_at' => $at,
            'last_retention_metadata_count' => 0,
        ]);

        return [
            'retention_days' => $retentionDays,
            'attempt_metadata_cleared' => 0,
            'snapshot_fields_removed' => 0,
        ];
    }
}
