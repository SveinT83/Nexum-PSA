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
    ) {}

    /**
     * Persist the assessment and return the created model.
     *
     * Expected input keys: title, optional description, scope, and optional
     * client_id when scope is "client".
     */
    public function handle(array $data): RiskAssessment
    {
        return app(\App\Modules\Risk\Support\RiskMutation::class)->run('risk.create', null, function ($lockedAssessment) use ($data) {

            $data = $this->settings->assessmentDefaults($data);
            if (($data['scope'] ?? 'internal') === 'client' && ! \App\Models\Clients\Client::whereKey($data['client_id'] ?? null)->where('active', true)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['client_id' => 'Select an active Client for client scope.']);
            }
            if (($data['scope'] ?? 'internal') === 'internal' && ! empty($data['client_id'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['client_id' => 'Internal scope must not include a Client.']);
            }

            return RiskAssessment::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'client_id' => $data['scope'] === 'internal' ? null : ($data['client_id'] ?? null),
                'work_context_id' => $this->workContexts
                    ->fromClientId($data['scope'] === 'internal' ? null : ($data['client_id'] ?? null))
                    ->id,
            ]);

        });
    }
}
