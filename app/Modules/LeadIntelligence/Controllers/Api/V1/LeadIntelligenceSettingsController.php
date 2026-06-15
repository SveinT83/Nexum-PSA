<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Support\LeadIntelligenceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadIntelligenceSettingsController extends Controller
{
    public function show(LeadIntelligenceSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->get()]);
    }

    public function update(Request $request, LeadIntelligenceSettings $settings): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'auto_create_clients' => ['sometimes', 'boolean'],
            'default_client_status' => ['sometimes', 'string', 'max:100'],
            'auto_create_contacts' => ['sometimes', 'boolean'],
            'auto_add_to_marketing_lists' => ['sometimes', 'boolean'],
            'allow_generic_company_emails' => ['sometimes', 'boolean'],
            'allow_role_based_emails' => ['sometimes', 'boolean'],
            'allow_named_work_emails' => ['sometimes', 'boolean'],
            'never_auto_use_private_email_domains' => ['sometimes', 'boolean'],
            'allowed_roles' => ['sometimes', 'array'],
            'allowed_roles.*' => ['string', 'max:100'],
            'default_rescan_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'max_pages_per_domain' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'max_tokens_per_run' => ['sometimes', 'integer', 'min:1', 'max:10000000'],
            'max_new_leads_per_run' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'require_source_url_for_contacts' => ['sometimes', 'boolean'],
            'require_role_for_named_contacts' => ['sometimes', 'boolean'],
            'minimum_company_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'minimum_contact_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'ai_discovery_planning_enabled' => ['sometimes', 'boolean'],
            'ai_discovery_planning_required' => ['sometimes', 'boolean'],
            'ai_discovery_planning_prompt' => ['sometimes', 'string', 'max:12000'],
            'ai_candidate_review_enabled' => ['sometimes', 'boolean'],
            'ai_candidate_review_required' => ['sometimes', 'boolean'],
            'ai_candidate_review_prompt' => ['sometimes', 'string', 'max:12000'],
            'discovery_sources' => ['sometimes', 'array'],
            'discovery_sources.*' => ['string', 'max:100'],
            'brreg_base_url' => ['sometimes', 'url', 'max:500'],
            'web_search_enabled' => ['sometimes', 'boolean'],
            'web_search_provider' => ['sometimes', 'string', Rule::in(['ai_provider', 'endpoint', 'disabled'])],
            'web_search_endpoint_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'web_search_results_per_query' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        foreach ($this->booleanKeys() as $key) {
            if ($request->has($key)) {
                $validated[$key] = $request->boolean($key);
            }
        }

        if ($request->has('web_search_endpoint_url') && ! $request->has('web_search_provider') && filled($validated['web_search_endpoint_url'] ?? null)) {
            $validated['web_search_provider'] = 'endpoint';
        }

        return response()->json(['data' => $settings->update(array_merge($settings->get(), $validated))]);
    }

    private function booleanKeys(): array
    {
        return [
            'enabled',
            'auto_create_clients',
            'auto_create_contacts',
            'auto_add_to_marketing_lists',
            'allow_generic_company_emails',
            'allow_role_based_emails',
            'allow_named_work_emails',
            'never_auto_use_private_email_domains',
            'require_source_url_for_contacts',
            'require_role_for_named_contacts',
            'ai_discovery_planning_enabled',
            'ai_discovery_planning_required',
            'ai_candidate_review_enabled',
            'ai_candidate_review_required',
            'web_search_enabled',
        ];
    }
}
