<?php

use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadIntelligenceEvaluationController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadIntelligencePromotionController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadIntelligenceSettingsController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadResearchRunController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadScanLedgerController;
use App\Modules\LeadIntelligence\Controllers\Api\V1\LeadSegmentController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('lead-intelligence/settings', [LeadIntelligenceSettingsController::class, 'show'])
    ->name('lead-intelligence.settings.show')
    ->middleware(CheckAbilities::class.':lead-intelligence.read');

Route::patch('lead-intelligence/settings', [LeadIntelligenceSettingsController::class, 'update'])
    ->name('lead-intelligence.settings.update')
    ->middleware(CheckAbilities::class.':lead-intelligence.manage');

Route::get('lead-segments', [LeadSegmentController::class, 'index'])
    ->name('lead-segments.index')
    ->middleware(CheckAbilities::class.':lead-intelligence.read');
Route::post('lead-segments', [LeadSegmentController::class, 'store'])
    ->name('lead-segments.store')
    ->middleware(CheckAbilities::class.':lead-intelligence.manage');
Route::get('lead-segments/{segment}', [LeadSegmentController::class, 'show'])
    ->name('lead-segments.show')
    ->middleware(CheckAbilities::class.':lead-intelligence.read');
Route::patch('lead-segments/{segment}', [LeadSegmentController::class, 'update'])
    ->name('lead-segments.update')
    ->middleware(CheckAbilities::class.':lead-intelligence.manage');

Route::post('lead-research-runs', [LeadResearchRunController::class, 'store'])
    ->name('lead-research-runs.store')
    ->middleware(CheckAbilities::class.':lead-intelligence.run');
Route::get('lead-research-runs/{run}', [LeadResearchRunController::class, 'show'])
    ->name('lead-research-runs.show')
    ->middleware(CheckAbilities::class.':lead-intelligence.read');

Route::get('lead-scan-ledger', [LeadScanLedgerController::class, 'index'])
    ->name('lead-scan-ledger.index')
    ->middleware(CheckAbilities::class.':lead-intelligence.read');

Route::post('lead-intelligence/evaluate-contact', [LeadIntelligenceEvaluationController::class, 'evaluateContact'])
    ->name('lead-intelligence.evaluate-contact')
    ->middleware(CheckAbilities::class.':lead-intelligence.run');

Route::post('lead-intelligence/promote-candidate', [LeadIntelligencePromotionController::class, 'promoteCandidate'])
    ->name('lead-intelligence.promote-candidate')
    ->middleware(CheckAbilities::class.':lead-intelligence.run');
