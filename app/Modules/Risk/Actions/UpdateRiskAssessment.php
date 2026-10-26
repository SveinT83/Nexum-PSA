<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskAssessment;
use App\Modules\WorkContext\Actions\ResolveWorkContext;

/**
 * Updates assessment metadata without touching scoring or history.
 *
 * This action is intentionally scoped to title, description, and client scope.
 * Item updates, approval, and deletion have separate actions because they
 * carry different business rules and audit implications.
 */
class UpdateRiskAssessment
{
    public function __construct(private readonly ResolveWorkContext $workContexts) {}

    /**
     * Apply validated metadata changes to an existing assessment.
     */
    public function handle(RiskAssessment $assessment, array $data): RiskAssessment
    {
        return app(\App\Modules\Risk\Support\RiskMutation::class)->run('risk.update', $assessment->id, function ($lockedAssessment) use ($assessment, $data) {
            $assessment = $lockedAssessment;
            if (($data['scope'] ?? 'internal') === 'client' && ! \App\Models\Clients\Client::whereKey($data['client_id'] ?? null)->where('active', true)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['client_id' => 'Select an active Client for client scope.']);
            }
            if (($data['scope'] ?? 'internal') === 'internal' && ! empty($data['client_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['client_id' => 'Internal scope must not include a Client.']);
            }

            $clientId = $data['scope'] === 'internal' ? null : ($data['client_id'] ?? null);

            $assessment->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'client_id' => $clientId,
                'work_context_id' => $this->workContexts->fromClientId($clientId)->id,
            ]);

            return $assessment;

        });
    }
}
