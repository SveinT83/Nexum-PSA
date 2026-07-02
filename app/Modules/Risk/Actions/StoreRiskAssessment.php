<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskAssessment;
use App\Modules\Risk\Support\RiskSettings;
use App\Modules\WorkContext\Actions\ResolveWorkContext;

/**
 * Creates a new risk assessment from validated controller input.
 *
 * The UI submits a human-facing scope value. This action is the single place
 * that translates that value into persistence rules: internal assessments have
 * no client_id, while client-scoped assessments store the selected client id.
 */
class StoreRiskAssessment
{
    public function __construct(
        private readonly RiskSettings $settings,
        private readonly ResolveWorkContext $workContexts,
    )
    {
    }

    /**
     * Persist the assessment and return the created model.
     *
     * Expected input keys: title, optional description, scope, and optional
     * client_id when scope is "client".
     */
    public function handle(array $data): RiskAssessment
    {
        $data = $this->settings->assessmentDefaults($data);

        return RiskAssessment::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'client_id' => $data['scope'] === 'internal' ? null : ($data['client_id'] ?? null),
            'work_context_id' => $this->workContexts
                ->fromClientId($data['scope'] === 'internal' ? null : ($data['client_id'] ?? null))
                ->id,
        ]);
    }
}
