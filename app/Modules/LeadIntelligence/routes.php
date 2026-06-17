<?php

use App\Modules\LeadIntelligence\Controllers\Admin\LeadIntelligenceSettingsController;
use App\Modules\LeadIntelligence\Controllers\Tech\LeadResearchRunController;
use App\Modules\LeadIntelligence\Controllers\Tech\LeadScanLedgerController;
use App\Modules\LeadIntelligence\Controllers\Tech\LeadSegmentController;
use Illuminate\Support\Facades\Route;

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
