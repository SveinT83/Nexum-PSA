<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use App\Modules\Risk\Support\RiskSettings;
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
    public function __construct(private readonly RiskSettings $settings) {}

    /**
     * Create the item under the given assessment.
     *
     * The parent assessment is moved from "new" to "in_progress" once an item
     * exists because work has started on the analysis.
     */
    public function handle(RiskAssessment $assessment, array $data): RiskItem
    {
        return app(\App\Modules\Risk\Support\RiskMutation::class)->run('risk.update', $assessment->id, function ($lockedAssessment) use ($assessment, $data) {
            $assessment = $lockedAssessment;

            $input = $data;
            $data = $this->settings->itemDefaults($data);
            // Explicit null clears the review date; omission uses the configured default.
            if (array_key_exists('next_review_at', $input)) {
                $data['next_review_at'] = $input['next_review_at'];
            }

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

                if (in_array($assessment->status, ['new', 'approved'], true)) {
                    $assessment->update(['status' => 'in_progress']);
                }

                return $item;
            });

            return $item;

        });
    }
}
