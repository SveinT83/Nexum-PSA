<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Actions\PromoteLeadCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadIntelligencePromotionController extends Controller
{
    public function promoteCandidate(Request $request, PromoteLeadCandidate $promote): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => ['sometimes', 'boolean'],
            'lead_research_run_id' => ['nullable', Rule::exists('lead_research_runs', 'id')],
            'marketing_list_ids' => ['nullable', 'array'],
            'marketing_list_ids.*' => ['integer', Rule::exists('marketing_lists', 'id')],
            'company' => ['required', 'array'],
            'company.name' => ['required', 'string', 'max:255'],
            'company.org_no' => ['nullable', 'string', 'max:50'],
            'company.website' => ['nullable', 'string', 'max:255'],
            'company.email' => ['nullable', 'email', 'max:255'],
            'company.shared_email' => ['nullable', 'email', 'max:255'],
            'company.billing_email' => ['nullable', 'email', 'max:255'],
            'company.source_type' => ['nullable', 'string', 'max:100'],
            'company.source_url' => ['nullable', 'string', 'max:2000'],
            'company.source_title' => ['nullable', 'string', 'max:255'],
            'company.excerpt' => ['nullable', 'string', 'max:5000'],
            'company.confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'company.score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'company.company_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contacts' => ['nullable', 'array'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
            'contacts.*.display_name' => ['nullable', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.role' => ['nullable', 'string', 'max:255'],
            'contacts.*.job_title' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.type' => ['nullable', 'string', 'max:50'],
            'contacts.*.source_type' => ['nullable', 'string', 'max:100'],
            'contacts.*.source_url' => ['nullable', 'string', 'max:2000'],
            'contacts.*.source_title' => ['nullable', 'string', 'max:255'],
            'contacts.*.excerpt' => ['nullable', 'string', 'max:5000'],
            'contacts.*.confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contacts.*.score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contacts.*.contact_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'contacts.*.company_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        return response()->json([
            'data' => $promote->handle($validated),
        ], ! empty($validated['dry_run']) ? 200 : 201);
    }
}
