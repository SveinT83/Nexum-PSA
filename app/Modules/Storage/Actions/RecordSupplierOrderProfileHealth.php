<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordSupplierOrderProfileHealth
{
    public function failure(
        PurchaseOrderImportProfile $profile,
        int $circuitBreakerFailures,
        string $reason,
        ?CarbonImmutable $at = null,
    ): PurchaseOrderImportProfile {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw ValidationException::withMessages([
                'reason' => 'Profile failure requires a bounded machine reason.',
            ]);
        }

        $at ??= CarbonImmutable::now();
        $threshold = max(1, $circuitBreakerFailures);

        return DB::transaction(function () use ($profile, $threshold, $reason, $at): PurchaseOrderImportProfile {
            $locked = PurchaseOrderImportProfile::query()
                ->lockForUpdate()
                ->findOrFail($profile->id);

            // A late worker result must never undo an explicit pause or retirement.
            if (in_array($locked->lifecycle_state, [
                PurchaseOrderImportProfile::STATE_PAUSED,
                PurchaseOrderImportProfile::STATE_RETIRED,
            ], true)) {
                return $locked->fresh();
            }

            $failures = ((int) $locked->consecutive_failures) + 1;
            $updates = [
                'consecutive_failures' => $failures,
                'health_state' => 'degraded',
                'last_matched_at' => $at,
                'pause_reason' => $reason,
            ];

            if ($failures >= $threshold) {
                $updates = [
                    ...$updates,
                    'lifecycle_state' => PurchaseOrderImportProfile::STATE_PAUSED,
                    'health_state' => 'paused',
                    'paused_at' => $at,
                    'pause_reason' => 'circuit_breaker:'.$reason,
                ];
            }

            $locked->forceFill($updates)->save();

            return $locked->fresh();
        });
    }

    public function result(
        PurchaseOrderImportProfile $profile,
        bool $success,
        ?CarbonImmutable $at = null,
    ): PurchaseOrderImportProfile {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($profile, $success, $at): PurchaseOrderImportProfile {
            $locked = PurchaseOrderImportProfile::query()
                ->lockForUpdate()
                ->findOrFail($profile->id);

            // Manual lifecycle decisions win over results from an older processing claim.
            if (in_array($locked->lifecycle_state, [
                PurchaseOrderImportProfile::STATE_PAUSED,
                PurchaseOrderImportProfile::STATE_RETIRED,
            ], true)) {
                return $locked->fresh();
            }

            $updates = ['last_matched_at' => $at];
            if ($success) {
                $updates = [
                    ...$updates,
                    'health_state' => 'healthy',
                    'consecutive_failures' => 0,
                    'last_success_at' => $at,
                    'paused_at' => null,
                    'pause_reason' => null,
                ];
            }

            $locked->forceFill($updates)->save();

            return $locked->fresh();
        });
    }
}
