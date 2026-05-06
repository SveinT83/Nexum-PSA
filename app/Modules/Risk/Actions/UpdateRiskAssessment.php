<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskAssessment;

/**
 * Updates assessment metadata without touching scoring or history.
 *
 * This action is intentionally scoped to title, description, and client scope.
 * Item updates, approval, and deletion have separate actions because they
 * carry different business rules and audit implications.
 */
class UpdateRiskAssessment
{
    /**
     * Apply validated metadata changes to an existing assessment.
     */
    public function handle(RiskAssessment $assessment, array $data): RiskAssessment
    {
        $assessment->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'client_id' => $data['scope'] === 'internal' ? null : ($data['client_id'] ?? null),
        ]);

        return $assessment;
    }
}
