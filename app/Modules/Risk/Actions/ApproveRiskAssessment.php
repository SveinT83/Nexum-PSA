<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskAssessment;
use App\Modules\Risk\Support\RiskMutation;
use Illuminate\Support\Facades\Auth;

/** Sign off only the current complete addressed item set; retain prior stamps on reopening. */
class ApproveRiskAssessment
{
    public function handle(RiskAssessment $assessment): bool
    {
        return app(RiskMutation::class)->run('risk.view', $assessment->id, function ($fresh) {
            $fresh->load('items');
            if (! $fresh->is_approvable) {
                return false;
            }
            if ($fresh->status === 'approved') {
                return true;
            }
            $fresh->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => Auth::id()]);

            return true;
        });
    }
}
