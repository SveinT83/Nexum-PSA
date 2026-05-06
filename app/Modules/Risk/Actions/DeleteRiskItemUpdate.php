<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskItemUpdate;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a risk item history row and repairs the parent item snapshot.
 *
 * RiskItem stores the current state for fast listing, while RiskItemUpdate
 * stores the audit trail. When one update is removed, the current state must be
 * synchronized from the newest remaining update or the UI will show stale
 * status/score information.
 */
class DeleteRiskItemUpdate
{
    /**
     * Delete the update inside a transaction and resync status, likelihood, and
     * impact from the latest remaining update.
     */
    public function handle(RiskItemUpdate $update): void
    {
        $item = $update->riskItem;

        DB::transaction(function () use ($item, $update) {
            $update->delete();

            $latest = $item->updates()->latest()->first();

            if ($latest) {
                $item->status = $latest->status;
                $item->likelihood = $latest->likelihood;
                $item->impact = $latest->impact;
                $item->save();
            }
        });
    }
}
