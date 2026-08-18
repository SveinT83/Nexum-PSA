<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Support\Facades\DB;

/**
 * Linearize a durable cancellation intent after the account provider lease
 * has drained any in-flight provider read and hidden Store persistence.
 */
final class EmailProviderReconciliationCancellationTransition
{
    public function transition(int $runId): bool
    {
        return DB::transaction(function () use ($runId): bool {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($runId);
            if (! $run || $run->terminal()) {
                return false;
            }
            if ($run->status === EmailProviderReconciliationRun::STATUS_CANCELLING) {
                return true;
            }
            if ($run->cancellation_requested_at === null
                || (int) $run->active_slot !== 1) {
                return false;
            }

            $attributes = [
                'status' => EmailProviderReconciliationRun::STATUS_CANCELLING,
                'last_progress_at' => now(),
            ];
            if ($run->final_summary_status !== null
                || $run->phase === EmailProviderReconciliationRun::PHASE_SUMMARY) {
                $attributes = [
                    ...$attributes,
                    ...EmailProviderReconciliationRun::emptyFinalSummary(),
                    'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_END,
                ];
            }
            $run->forceFill($attributes)->save();

            return true;
        }, 3);
    }
}
