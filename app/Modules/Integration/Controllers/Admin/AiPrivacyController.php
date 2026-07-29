<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\AiAccessEvent;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiAgentGovernancePolicy;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Services\AiDataEgressPolicyEvaluator;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AiPrivacyController extends Controller
{
    public function index(ApiAbilityCatalog $abilities): View
    {
        return view('integration::Tech.Admin.System.Integrations.ai.privacy', [
            'policy' => AiDataEgressPolicy::installation(),
            'providers' => AiProvider::query()->orderBy('name')->get(),
            'governance' => AiProviderGovernanceProfile::query()->get()->keyBy('ai_provider_id'),
            'modelPolicies' => AiModelGovernancePolicy::query()->get()->groupBy('ai_provider_id'),
            'agents' => AiAgent::query()->with('provider')->orderBy('name')->get(),
            'agentPolicies' => AiAgentGovernancePolicy::query()->get()->keyBy('ai_agent_id'),
            'workloads' => AiWorkloadProfile::query()->with(['bindings.token'])->latest()->get(),
            'events' => AiAccessEvent::query()->latest()->limit(100)->get(),
            'coordinatorAbilities' => collect($abilities->all())->filter(fn (array $details, string $ability): bool => $abilities->isReadOnly($ability)),
            'processingModes' => AiDataEgressPolicyEvaluator::MODES,
            'dataProfiles' => AiDataEgressPolicyEvaluator::PROFILES,
        ]);
    }

    public function updatePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ai_enabled' => ['nullable', 'boolean'],
            'external_processing_enabled' => ['nullable', 'boolean'],
            'privacy_gateway_enabled' => ['nullable', 'boolean'],
            'direct_external_enabled' => ['nullable', 'boolean'],
            'allowed_processing_modes' => ['required', 'array', 'min:1'],
            'allowed_processing_modes.*' => [Rule::in(AiDataEgressPolicyEvaluator::MODES)],
            'maximum_data_profile' => ['required', Rule::in(AiDataEgressPolicyEvaluator::PROFILES)],
            'context_scope' => ['required', Rule::in(['internal_only', 'selected_clients', 'selected_work_contexts'])],
            'maximum_query_days' => ['required', 'integer', 'between:1,366'],
            'maximum_page_size' => ['required', 'integer', 'between:1,200'],
            'maximum_results' => ['required', 'integer', 'between:1,5000'],
            'requests_per_minute' => ['required', 'integer', 'between:1,600'],
            'audit_retention_days' => ['required', 'integer', 'between:30,730'],
            'retain_denials' => ['nullable', 'boolean'],
            'payload_retention_enabled' => ['nullable', 'boolean'],
            'payload_retention_days' => ['required', 'integer', 'between:1,30'],
            'employee_identification_allowed' => ['nullable', 'boolean'],
            'coordination_purpose' => ['nullable', 'string', 'max:5000'],
            'staff_transparency_reference' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'change_reason' => ['required', 'string', 'max:500'],
        ]);
        $booleans = ['ai_enabled', 'external_processing_enabled', 'privacy_gateway_enabled', 'direct_external_enabled', 'retain_denials', 'payload_retention_enabled', 'employee_identification_allowed'];
        foreach ($booleans as $field) {
            $data[$field] = $request->boolean($field);
        }
        if ($data['direct_external_enabled'] && ! $data['external_processing_enabled']) {
            throw ValidationException::withMessages(['direct_external_enabled' => 'Direct external processing requires external processing.']);
        }
        if (in_array('privacy_relay', $data['allowed_processing_modes'], true) && ! $data['privacy_gateway_enabled']) {
            throw ValidationException::withMessages(['privacy_gateway_enabled' => 'Privacy relay requires the privacy gateway.']);
        }
        if ($data['employee_identification_allowed'] && (blank($data['coordination_purpose']) || blank($data['staff_transparency_reference']))) {
            throw ValidationException::withMessages(['coordination_purpose' => 'Purpose and staff transparency documentation are required for employee identification.']);
        }

        DB::transaction(function () use ($data, $request): void {
            AiDataEgressPolicy::installation();
            $policy = AiDataEgressPolicy::query()->where('scope_key', AiDataEgressPolicy::INSTALLATION_SCOPE)
                ->lockForUpdate()->firstOrFail();
            $reason = $data['change_reason'];
            unset($data['change_reason']);
            $policy->fill($data);
            $policy->revision++;
            $policy->updated_by = $request->user()->id;
            $policy->reviewed_by = $request->user()->id;
            $policy->reviewed_at = now();
            $policy->save();
            $policy->revisions()->create([
                'revision' => $policy->revision,
                'policy_snapshot' => $policy->fresh()->toArray(),
                'changed_by' => $request->user()->id,
                'change_reason' => $reason,
            ]);
        });

        return back()->with('success', 'AI data-egress policy updated with a new revision.');
    }

    public function updateProvider(Request $request, AiProvider $provider, AiDataEgressPolicyEvaluator $evaluator): RedirectResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', 'max:5000'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'processing_regions' => ['required', 'array', 'min:1'],
            'processing_regions.*' => ['string', 'max:100'],
            'support_regions' => ['nullable', 'array'],
            'support_regions.*' => ['string', 'max:100'],
            'dpa_status' => ['required', Rule::in(['not_reviewed', 'approved', 'rejected'])],
            'dpa_reference' => ['nullable', 'string', 'max:1000'],
            'subprocessor_notes' => ['required', 'string', 'max:5000'],
            'transfer_assessment' => ['required', 'string', 'max:5000'],
            'retention_declaration' => ['required', 'string', 'max:5000'],
            'training_declaration' => ['required', 'string', 'max:5000'],
            'dpia_status' => ['required', Rule::in(['required', 'completed', 'not_required', 'rejected'])],
            'dpia_rationale' => ['required', 'string', 'max:5000'],
            'allowed_processing_modes' => ['required', 'array', 'min:1'],
            'allowed_processing_modes.*' => [Rule::in(AiDataEgressPolicyEvaluator::MODES)],
            'maximum_data_profile' => ['required', Rule::in(AiDataEgressPolicyEvaluator::PROFILES)],
            'is_approved' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $installation = AiDataEgressPolicy::installation();
        if (array_diff($data['allowed_processing_modes'], $installation->allowed_processing_modes ?? []) !== []) {
            throw ValidationException::withMessages(['allowed_processing_modes' => 'Provider modes cannot exceed the installation policy.']);
        }
        if (! $evaluator->profileFits($data['maximum_data_profile'], $installation->maximum_data_profile)) {
            throw ValidationException::withMessages(['maximum_data_profile' => 'Provider profile cannot exceed the installation policy.']);
        }
        $data['is_approved'] = $request->boolean('is_approved');
        $data['is_active'] = $request->boolean('is_active');
        $data['reviewed_by'] = $request->user()->id;
        $data['reviewed_at'] = now();
        AiProviderGovernanceProfile::query()->updateOrCreate(['ai_provider_id' => $provider->id], $data);

        return back()->with('success', 'Provider governance record updated.');
    }

    public function updateModel(Request $request, AiProvider $provider, AiDataEgressPolicyEvaluator $evaluator): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['required', 'string', 'max:255'],
            'processing_mode' => ['required', Rule::in(AiDataEgressPolicyEvaluator::MODES)],
            'maximum_data_profile' => ['required', Rule::in(AiDataEgressPolicyEvaluator::PROFILES)],
            'is_approved' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $installation = AiDataEgressPolicy::installation();
        $providerGovernance = AiProviderGovernanceProfile::query()->where('ai_provider_id', $provider->id)->first();
        if (! in_array($data['processing_mode'], $installation->allowed_processing_modes ?? [], true)
            || ($providerGovernance && ! in_array($data['processing_mode'], $providerGovernance->allowed_processing_modes ?? [], true))) {
            throw ValidationException::withMessages(['processing_mode' => 'Model mode cannot exceed installation or provider policy.']);
        }
        if (! $evaluator->profileFits($data['maximum_data_profile'], $installation->maximum_data_profile)
            || ($providerGovernance && ! $evaluator->profileFits($data['maximum_data_profile'], $providerGovernance->maximum_data_profile))) {
            throw ValidationException::withMessages(['maximum_data_profile' => 'Model profile cannot exceed installation or provider policy.']);
        }
        $data['is_approved'] = $request->boolean('is_approved');
        $data['reviewed_by'] = $request->user()->id;
        $data['reviewed_at'] = now();
        AiModelGovernancePolicy::query()->updateOrCreate([
            'ai_provider_id' => $provider->id,
            'model' => $data['model'],
        ], $data);

        return back()->with('success', 'Model governance policy updated.');
    }

    public function updateAgent(Request $request, AiAgent $agent, AiDataEgressPolicyEvaluator $evaluator): RedirectResponse
    {
        $data = $request->validate([
            'processing_mode' => ['required', Rule::in(AiDataEgressPolicyEvaluator::MODES)],
            'maximum_data_profile' => ['required', Rule::in(AiDataEgressPolicyEvaluator::PROFILES)],
            'is_approved' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $model = $agent->model ?: $agent->provider?->default_model;
        $modelPolicy = AiModelGovernancePolicy::query()
            ->where('ai_provider_id', $agent->ai_provider_id)
            ->where('model', $model)
            ->first();
        if (! $modelPolicy) {
            throw ValidationException::withMessages(['processing_mode' => 'Approve the selected provider model before approving this agent.']);
        }
        if ($data['processing_mode'] !== $modelPolicy->processing_mode
            || ! $evaluator->profileFits($data['maximum_data_profile'], $modelPolicy->maximum_data_profile)) {
            throw ValidationException::withMessages(['processing_mode' => 'Agent policy cannot exceed or bypass its model policy.']);
        }
        $data['is_approved'] = $request->boolean('is_approved');
        $data['reviewed_by'] = $request->user()->id;
        $data['reviewed_at'] = now();
        AiAgentGovernancePolicy::query()->updateOrCreate(['ai_agent_id' => $agent->id], $data);

        return back()->with('success', 'Agent governance override updated.');
    }

    public function storeWorkload(Request $request, AiDataEgressPolicyEvaluator $evaluator, ApiAbilityCatalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:5000'],
            'ai_provider_id' => ['nullable', 'uuid', 'exists:ai_providers,id'],
            'model' => ['nullable', 'string', 'max:255'],
            'processing_mode' => ['required', Rule::in(AiDataEgressPolicyEvaluator::MODES)],
            'maximum_data_profile' => ['required', Rule::in(AiDataEgressPolicyEvaluator::PROFILES)],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in($catalog->values())],
            'allowed_client_ids' => ['nullable', 'array'],
            'allowed_client_ids.*' => ['integer'],
            'allowed_work_context_ids' => ['nullable', 'array'],
            'allowed_work_context_ids.*' => ['integer'],
            'expires_at' => ['required', 'date', 'after:today'],
        ]);
        if (collect($data['abilities'])->contains(fn (string $ability): bool => ! $catalog->isReadOnly($ability))) {
            throw ValidationException::withMessages(['abilities' => 'Coordinator workloads can only use read abilities.']);
        }
        $installation = AiDataEgressPolicy::installation();
        if (! in_array($data['processing_mode'], $installation->allowed_processing_modes ?? [], true)
            || ! $evaluator->profileFits($data['maximum_data_profile'], $installation->maximum_data_profile)) {
            throw ValidationException::withMessages(['processing_mode' => 'Workload policy cannot exceed the installation maximum.']);
        }
        if ($data['processing_mode'] !== 'local_only') {
            if (blank($data['ai_provider_id'] ?? null) || blank($data['model'] ?? null)) {
                throw ValidationException::withMessages(['ai_provider_id' => 'External workloads require an approved provider and model.']);
            }
            $modelPolicy = AiModelGovernancePolicy::query()
                ->where('ai_provider_id', $data['ai_provider_id'])->where('model', $data['model'])->first();
            if (! $modelPolicy || ! $modelPolicy->is_approved || $modelPolicy->expires_at?->isPast()
                || $modelPolicy->processing_mode !== $data['processing_mode']
                || ! $evaluator->profileFits($data['maximum_data_profile'], $modelPolicy->maximum_data_profile)) {
                throw ValidationException::withMessages(['model' => 'Workload cannot exceed or bypass the approved model policy.']);
            }
        }
        $data = array_merge($data, [
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
            'is_approved' => true,
            'is_active' => true,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'created_by' => $request->user()->id,
        ]);
        AiWorkloadProfile::query()->create($data);

        return back()->with('success', 'Approved coordinator workload created.');
    }

    public function storeToken(Request $request, AiWorkloadProfile $workload, AiDataEgressPolicyEvaluator $evaluator, ApiAbilityCatalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => ['required', 'date', 'after:today'],
            'requests_per_minute' => ['required', 'integer', 'between:1,600'],
            'allowed_networks' => ['nullable', 'array'],
            'allowed_networks.*' => ['ip'],
        ]);
        if (! $workload->is_active || ! $workload->is_approved
            || collect($workload->abilities)->contains(fn (string $ability): bool => ! $catalog->isReadOnly($ability))) {
            throw ValidationException::withMessages(['name' => 'The workload is not approved for a read-only token.']);
        }
        $governance = null;
        if ($workload->processing_mode !== 'local_only') {
            $modelPolicy = AiModelGovernancePolicy::query()
                ->where('ai_provider_id', $workload->ai_provider_id)->where('model', $workload->model)->first();
            if (! $modelPolicy || ! $modelPolicy->is_approved || $modelPolicy->expires_at?->isPast()
                || $modelPolicy->processing_mode !== $workload->processing_mode
                || ! $evaluator->profileFits($workload->maximum_data_profile, $modelPolicy->maximum_data_profile)) {
                throw ValidationException::withMessages(['name' => 'The workload model approval is missing, expired, or too narrow.']);
            }
            $governance = AiProviderGovernanceProfile::query()->where('ai_provider_id', $workload->ai_provider_id)->first();
        }
        $decision = $evaluator->evaluate(AiDataEgressPolicy::installation(), $workload->processing_mode, $workload->maximum_data_profile, governance: $governance, workload: $workload);
        if (! $decision->allowed) {
            throw ValidationException::withMessages(['name' => 'Policy denied token creation: '.$decision->reasonCode]);
        }
        $token = $request->user()->createToken($data['name'], $workload->abilities);
        AiWorkloadTokenBinding::query()->create([
            'personal_access_token_id' => $token->accessToken->id,
            'ai_workload_profile_id' => $workload->id,
            'expires_at' => $data['expires_at'],
            'allowed_networks' => $data['allowed_networks'] ?? [],
            'requests_per_minute' => min($data['requests_per_minute'], AiDataEgressPolicy::installation()->requests_per_minute),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Coordinator token created: '.$token->plainTextToken.'. Save it now; it will not be shown again.');
    }

    public function revokeToken(AiWorkloadTokenBinding $binding): RedirectResponse
    {
        $binding->update(['revoked_at' => now()]);
        $binding->token?->delete();

        return back()->with('success', 'Coordinator token revoked.');
    }
}
