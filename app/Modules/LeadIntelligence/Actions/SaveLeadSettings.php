<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\LeadIntelligence\Support\LeadIntelligenceSettings;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Web and API save the same validated merged policy. */
class SaveLeadSettings
{
    public function handle(array $input): array
    {
        foreach (['ai_discovery_planning_prompt', 'ai_candidate_review_prompt'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] === null) {
                $input[$key] = '';
            }
        }
        $validated = validator($input, [
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
        ])->validate();

        $settings = app(LeadIntelligenceSettings::class);
        if (array_key_exists('web_search_endpoint_url', $validated) && ! array_key_exists('web_search_provider', $validated)
            && filled($validated['web_search_endpoint_url'])) {
            $validated['web_search_provider'] = 'endpoint';
        }
        $merged = array_merge($settings->get(), $validated);
        if ($merged['web_search_enabled'] && $merged['web_search_provider'] === 'endpoint' && blank($merged['web_search_endpoint_url'])) {
            throw ValidationException::withMessages(['web_search_endpoint_url' => 'An endpoint URL is required for enabled endpoint search.']);
        }

        return $settings->update($merged);
    }
}
