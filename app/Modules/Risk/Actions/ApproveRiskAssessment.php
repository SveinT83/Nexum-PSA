<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskAssessment;
use Illuminate\Support\Facades\Auth;

/**
 * Finalizes a risk assessment when all risk items are addressed.
 *
 * This action is deliberately small because the approval rule lives on the
 * RiskAssessment model as the is_approvable computed attribute. Keeping the
 * mutation here gives controllers and future API/queue workflows one shared
 * place to stamp approval metadata.
 */
class ApproveRiskAssessment
{
    /**
     * Attempt to approve the assessment.
     *
     * Returns false instead of throwing when the assessment is not approvable so
     * callers can decide how to present the failed business rule to the user.
     */
    public function handle(RiskAssessment $assessment): bool
    {
        if (! $assessment->is_approvable) {
            return false;
        }

        $assessment->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => Auth::id(),
        ]);

        return true;
    }
}
