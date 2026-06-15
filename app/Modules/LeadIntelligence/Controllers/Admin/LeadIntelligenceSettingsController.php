<?php

namespace App\Modules\LeadIntelligence\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Support\LeadIntelligenceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeadIntelligenceSettingsController extends Controller
{
    public function edit(LeadIntelligenceSettings $settings): View
    {
        return view('leadintelligence::Admin.Settings.edit', [
            'settings' => $settings->get(),
        ]);
    }

    public function update(Request $request, LeadIntelligenceSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'default_client_status' => ['required', 'string', 'max:100'],
            'allowed_roles' => ['nullable', 'string', 'max:2000'],
            'default_rescan_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'max_pages_per_domain' => ['required', 'integer', 'min:1', 'max:10000'],
            'max_tokens_per_run' => ['required', 'integer', 'min:1', 'max:10000000'],
            'max_new_leads_per_run' => ['required', 'integer', 'min:1', 'max:100000'],
            'minimum_company_score' => ['required', 'integer', 'min:0', 'max:100'],
            'minimum_contact_score' => ['required', 'integer', 'min:0', 'max:100'],
            'ai_discovery_planning_prompt' => ['nullable', 'string', 'max:12000'],
            'ai_candidate_review_prompt' => ['nullable', 'string', 'max:12000'],
            'discovery_sources' => ['nullable', 'string', 'max:2000'],
            'brreg_base_url' => ['required', 'url', 'max:500'],
            'web_search_provider' => ['required', 'string', Rule::in(['ai_provider', 'endpoint', 'disabled'])],
            'web_search_endpoint_url' => [
                'nullable',
                'url',
                'max:500',
                Rule::requiredIf(fn (): bool => $request->boolean('web_search_enabled') && $request->input('web_search_provider') === 'endpoint'),
            ],
            'web_search_results_per_query' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        foreach ($this->booleanKeys() as $key) {
            $validated[$key] = $request->boolean($key);
        }

        $validated['allowed_roles'] = $this->splitList($validated['allowed_roles'] ?? '');
        $validated['discovery_sources'] = $this->splitList($validated['discovery_sources'] ?? '');

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.settings.lead-intelligence')
            ->with('success', 'Lead Intelligence settings were updated.');
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

    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $item): string => trim($item),
            preg_split('/[\r\n,]+/', $value) ?: [],
        )));
    }
}
