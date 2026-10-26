<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Modules\Risk\Support\RiskMutation;
use App\Modules\Risk\Support\RiskPolicy;

/** Soft deletion preserves history; all API child reads require an active parent. */
final class DeleteRiskRecord
{
    public function handle(RiskAssessment|RiskItem $record): void
    {
        $parent = $record instanceof RiskAssessment ? $record->id : $record->risk_assessment_id;
        app(RiskMutation::class)->run('risk.delete', $parent, function ($assessment) use ($record) {
            RiskPolicy::superuser();
            if ($record instanceof RiskAssessment) {
                $assessment->delete();
            } else {
                RiskPolicy::item($record->id)->delete();
                RiskMutation::reopen($assessment);
            }
        });
    }
}
