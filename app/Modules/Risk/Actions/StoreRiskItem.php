<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creates a risk item and its initial history entry.
 *
 * Risk items are living records. The risk_items table stores the current
 * snapshot, and risk_item_updates stores the historical timeline. This action
 * creates both records together so every item has a baseline audit entry from
 * the moment it is identified.
 */
class StoreRiskItem
{
    /**
     * Create the item under the given assessment.
     *
     * The parent assessment is moved from "new" to "in_progress" once an item
     * exists because work has started on the analysis.
     */
    public function handle(RiskAssessment $assessment, array $data): RiskItem
    {
        $item = DB::transaction(function () use ($assessment, $data) {
            $item = new RiskItem($data);
            $item->risk_assessment_id = $assessment->id;
            $item->save();

            RiskItemUpdate::create([
                'risk_item_id' => $item->id,
                'created_by' => Auth::id(),
                'note' => 'Initial risk identified',
                'status' => $item->status,
                'likelihood' => $item->likelihood,
                'impact' => $item->impact,
            ]);

            if ($assessment->status === 'new') {
                $assessment->update(['status' => 'in_progress']);
            }

            return $item;
        });

        return $item;
    }
}
