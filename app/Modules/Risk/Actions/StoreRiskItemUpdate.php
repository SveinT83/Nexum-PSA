<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Adds an audit update to a risk item and updates its current snapshot.
 *
 * Likelihood, impact, and status are treated as historical facts. They should
 * be changed through this action so the old state remains available in
 * risk_item_updates while risk_items remains convenient for list views.
 */
class StoreRiskItemUpdate
{
    /**
     * Persist the update and copy its current-state values back to RiskItem.
     *
     * If a previously approved assessment receives a new update, it is moved
     * back to "in_progress" because the signed-off state is no longer final.
     */
    public function handle(RiskItem $item, array $data): RiskItemUpdate
    {
        return DB::transaction(function () use ($item, $data) {
            $update = RiskItemUpdate::create([
                'risk_item_id' => $item->id,
                'created_by' => Auth::id(),
                'note' => $data['note'],
                'status' => $data['status'],
                'likelihood' => $data['likelihood'] ?? $item->likelihood,
                'impact' => $data['impact'] ?? $item->impact,
            ]);

            $item->status = $update->status;
            $item->likelihood = $update->likelihood;
            $item->impact = $update->impact;

            if (array_key_exists('next_review_at', $data)) {
                $item->next_review_at = $data['next_review_at'];
            }

            $item->save();

            $assessment = $item->assessment;
            if (in_array($assessment->status, ['new', 'approved'], true)) {
                $assessment->update(['status' => 'in_progress']);
            }

            return $update;
        });
    }
}
