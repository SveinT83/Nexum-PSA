<?php

use App\Modules\LeadIntelligence\Controllers\Admin\LeadIntelligenceSettingsController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadIntelligenceEvaluationController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadIntelligencePromotionController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadIntelligenceSettingsController as ApiLeadIntelligenceSettingsController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadResearchRunController as ApiLeadResearchRunController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadScanLedgerController as ApiLeadScanLedgerController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadSegmentController as ApiLeadSegmentController;
use App\Modules\LeadIntelligence\Controllers\Tech\LeadResearchRunController;
use App\Modules\LeadIntelligence\Controllers\Tech\LeadScanLedgerController;
use App\Modules\LeadIntelligence\Controllers\Tech\LeadSegmentController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Official API routes retain the legacy URL namespace.
if (($leadApiRoutes ?? false) === true) {
    Route::middleware([\App\Modules\LeadIntelligence\Support\LeadApiAccess::class, \App\Modules\LeadIntelligence\Actions\ExecuteLeadApiCommand::class])->group(function (): void {
        Route::get('lead-intelligence/context', [\App\Modules\LeadIntelligence\Controllers\Api\V1\LeadWorkspaceController::class, 'context'])->name('lead-intelligence.context')->defaults('lead_fields', ['lead_segment_id'])->middleware(CheckAbilities::class.':lead-intelligence.read')->defaults('lead_ability', 'lead-intelligence.read');
        Route::post('lead-segments/ai-draft', [\App\Modules\LeadIntelligence\Controllers\Api\V1\LeadWorkspaceController::class, 'draft'])->name('lead-segments.ai-draft')->defaults('lead_fields', ['prompt', 'lead_segment_id'])->middleware(CheckAbilities::class.':lead-intelligence.manage')->defaults('lead_ability', 'lead-intelligence.manage')->defaults('lead_external', 'ai');
        Route::post('lead-segments/{segment}/run-now', [\App\Modules\LeadIntelligence\Controllers\Api\V1\LeadWorkspaceController::class, 'runNow'])->name('lead-segments.run-now')->defaults('lead_fields', [])->middleware(CheckAbilities::class.':lead-intelligence.run')->defaults('lead_ability', 'lead-intelligence.run');
        Route::get('lead-research-runs', [\App\Modules\LeadIntelligence\Controllers\Api\V1\LeadWorkspaceController::class, 'runs'])->name('lead-research-runs.index')->defaults('lead_fields', ['status', 'lead_segment_id', 'page', 'per_page'])->middleware(CheckAbilities::class.':lead-intelligence.read')->defaults('lead_ability', 'lead-intelligence.read');
        Route::get('lead-research-runs/{run}/evidence', [\App\Modules\LeadIntelligence\Controllers\Api\V1\LeadWorkspaceController::class, 'evidence'])->name('lead-research-runs.evidence')->defaults('lead_fields', ['page', 'per_page'])->middleware(CheckAbilities::class.':lead-intelligence.read')->defaults('lead_ability', 'lead-intelligence.read');
        Route::get('lead-intelligence/operations/{command}', [\App\Modules\LeadIntelligence\Controllers\Api\V1\LeadWorkspaceController::class, 'operation'])->name('lead-intelligence.operations.show')->defaults('lead_fields', []);
        Route::get('lead-intelligence/settings', [ApiLeadIntelligenceSettingsController::class, 'show'])
            ->name('lead-intelligence.settings.show')->middleware(CheckAbilities::class.':lead-intelligence.read')
            ->defaults('lead_ability', 'lead-intelligence.read')->defaults('lead_fields', []);

        Route::patch('lead-intelligence/settings', [ApiLeadIntelligenceSettingsController::class, 'update'])
            ->name('lead-intelligence.settings.update')->middleware(CheckAbilities::class.':lead-intelligence.manage')
            ->defaults('lead_ability', 'lead-intelligence.manage')->defaults('lead_fields', ['enabled', 'auto_create_clients', 'default_client_status', 'auto_create_contacts', 'auto_add_to_marketing_lists', 'allow_generic_company_emails', 'allow_role_based_emails', 'allow_named_work_emails', 'never_auto_use_private_email_domains', 'allowed_roles', 'default_rescan_days', 'max_pages_per_domain', 'max_tokens_per_run', 'max_new_leads_per_run', 'require_source_url_for_contacts', 'require_role_for_named_contacts', 'minimum_company_score', 'minimum_contact_score', 'ai_discovery_planning_enabled', 'ai_discovery_planning_required', 'ai_discovery_planning_prompt', 'ai_candidate_review_enabled', 'ai_candidate_review_required', 'ai_candidate_review_prompt', 'discovery_sources', 'brreg_base_url', 'web_search_enabled', 'web_search_provider', 'web_search_endpoint_url', 'web_search_results_per_query']);

        Route::get('lead-segments', [ApiLeadSegmentController::class, 'index'])
            ->name('lead-segments.index')->middleware(CheckAbilities::class.':lead-intelligence.read')
            ->defaults('lead_ability', 'lead-intelligence.read')->defaults('lead_fields', ['q', 'enabled', 'page', 'per_page']);
        Route::post('lead-segments', [ApiLeadSegmentController::class, 'store'])
            ->name('lead-segments.store')->middleware(CheckAbilities::class.':lead-intelligence.manage')
            ->defaults('lead_ability', 'lead-intelligence.manage')->defaults('lead_fields', ['name', 'description', 'enabled', 'schedule_enabled', 'schedule_period', 'schedule_time', 'run_interval_days', 'target_new_leads_per_period', 'token_budget_per_period', 'token_budget_unlimited', 'max_runs_per_period', 'next_run_at', 'geography', 'geography_json', 'industries', 'industries_json', 'nace_codes', 'nace_codes_json', 'keywords', 'keywords_json', 'excluded_keywords', 'excluded_keywords_json', 'target_roles', 'target_roles_json', 'marketing_list_ids', 'marketing_list_ids_json', 'schedule_weekdays', 'schedule_weekdays_json', 'settings', 'settings_json']);
        Route::get('lead-segments/{segment}', [ApiLeadSegmentController::class, 'show'])
            ->name('lead-segments.show')->middleware(CheckAbilities::class.':lead-intelligence.read')
            ->defaults('lead_ability', 'lead-intelligence.read')->defaults('lead_fields', []);
        Route::patch('lead-segments/{segment}', [ApiLeadSegmentController::class, 'update'])
            ->name('lead-segments.update')->middleware(CheckAbilities::class.':lead-intelligence.manage')
            ->defaults('lead_ability', 'lead-intelligence.manage')->defaults('lead_fields', ['name', 'description', 'enabled', 'schedule_enabled', 'schedule_period', 'schedule_time', 'run_interval_days', 'target_new_leads_per_period', 'token_budget_per_period', 'token_budget_unlimited', 'max_runs_per_period', 'next_run_at', 'geography', 'geography_json', 'industries', 'industries_json', 'nace_codes', 'nace_codes_json', 'keywords', 'keywords_json', 'excluded_keywords', 'excluded_keywords_json', 'target_roles', 'target_roles_json', 'marketing_list_ids', 'marketing_list_ids_json', 'schedule_weekdays', 'schedule_weekdays_json', 'settings', 'settings_json']);

        Route::post('lead-research-runs', [ApiLeadResearchRunController::class, 'store'])
            ->name('lead-research-runs.store')->middleware(CheckAbilities::class.':lead-intelligence.run')
            ->defaults('lead_ability', 'lead-intelligence.run')->defaults('lead_fields', ['lead_segment_id', 'status', 'summary']);
        Route::get('lead-research-runs/{run}', [ApiLeadResearchRunController::class, 'show'])
            ->name('lead-research-runs.show')->middleware(CheckAbilities::class.':lead-intelligence.read')
            ->defaults('lead_ability', 'lead-intelligence.read')->defaults('lead_fields', []);

        Route::get('lead-scan-ledger', [ApiLeadScanLedgerController::class, 'index'])
            ->name('lead-scan-ledger.index')->middleware(CheckAbilities::class.':lead-intelligence.read')
            ->defaults('lead_ability', 'lead-intelligence.read')->defaults('lead_fields', ['domain', 'org_no', 'status', 'due_only', 'page', 'per_page']);

        Route::post('lead-intelligence/evaluate-contact', [LeadIntelligenceEvaluationController::class, 'evaluateContact'])
            ->name('lead-intelligence.evaluate-contact')->middleware(CheckAbilities::class.':lead-intelligence.run')
            ->defaults('lead_ability', 'lead-intelligence.run')->defaults('lead_fields', ['contact_id', 'client_id', 'source_evidence_id']);

        Route::post('lead-intelligence/promote-candidate', [LeadIntelligencePromotionController::class, 'promoteCandidate'])
            ->name('lead-intelligence.promote-candidate')->middleware(CheckAbilities::class.':lead-intelligence.run')
            ->defaults('lead_ability', 'lead-intelligence.run')->defaults('lead_fields', ['dry_run', 'lead_research_run_id', 'marketing_list_ids', 'company', 'contacts'])->defaults('lead_external', 'promotion');

    });

    return;
}

Route::redirect('/lead-intelligence', '/tech/lead-intelligence/segments')
    ->name('lead-intelligence.index');

Route::middleware('admin')->group(function (): void {
    Route::get('/admin/settings/lead-intelligence', [LeadIntelligenceSettingsController::class, 'edit'])
        ->name('admin.settings.lead-intelligence');
    Route::put('/admin/settings/lead-intelligence', [LeadIntelligenceSettingsController::class, 'update'])
        ->name('admin.settings.lead-intelligence.update');
});

Route::get('/lead-intelligence/segments', [LeadSegmentController::class, 'index'])
    ->name('lead-intelligence.segments.index');
Route::get('/lead-intelligence/segments/create', [LeadSegmentController::class, 'create'])
    ->name('lead-intelligence.segments.create');
Route::post('/lead-intelligence/segments/ai-draft', [LeadSegmentController::class, 'draftWithAi'])
    ->name('lead-intelligence.segments.ai-draft');
Route::post('/lead-intelligence/segments', [LeadSegmentController::class, 'store'])
    ->name('lead-intelligence.segments.store');
Route::get('/lead-intelligence/segments/{segment}/edit', [LeadSegmentController::class, 'edit'])
    ->name('lead-intelligence.segments.edit');
Route::put('/lead-intelligence/segments/{segment}', [LeadSegmentController::class, 'update'])
    ->name('lead-intelligence.segments.update');
Route::post('/lead-intelligence/segments/{segment}/run-now', [LeadSegmentController::class, 'runNow'])
    ->name('lead-intelligence.segments.run-now');

Route::get('/lead-intelligence/runs', [LeadResearchRunController::class, 'index'])
    ->name('lead-intelligence.runs.index');
Route::post('/lead-intelligence/runs', [LeadResearchRunController::class, 'store'])
    ->name('lead-intelligence.runs.store');
Route::get('/lead-intelligence/runs/{run}', [LeadResearchRunController::class, 'show'])
    ->name('lead-intelligence.runs.show');

Route::get('/lead-intelligence/scan-ledger', [LeadScanLedgerController::class, 'index'])
    ->name('lead-intelligence.scan-ledger.index');
