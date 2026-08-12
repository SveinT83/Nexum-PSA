<?php

use App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController;
use App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('sales/opportunities', [SalesOpportunityController::class, 'index'])
    ->name('sales.opportunities.index')
    ->middleware(CheckAbilities::class.':sales.read');

Route::post('sales/opportunities', [SalesOpportunityController::class, 'store'])
    ->name('sales.opportunities.store')
    ->middleware(CheckAbilities::class.':sales.create');

Route::get('sales/opportunities/{opportunity}', [SalesOpportunityController::class, 'show'])
    ->name('sales.opportunities.show')
    ->middleware(CheckAbilities::class.':sales.read');

Route::match(['put', 'patch'], 'sales/opportunities/{opportunity}', [SalesOpportunityController::class, 'update'])
    ->name('sales.opportunities.update')
    ->middleware(CheckAbilities::class.':sales.update');

Route::post('sales/opportunities/{opportunity}/lost', [SalesOpportunityController::class, 'markLost'])
    ->name('sales.opportunities.lost')
    ->middleware(CheckAbilities::class.':sales.update');

Route::post('sales/opportunities/{opportunity}/reopen', [SalesOpportunityController::class, 'reopen'])
    ->name('sales.opportunities.reopen')
    ->middleware(CheckAbilities::class.':sales.update');

Route::post('sales/opportunities/{opportunity}/activities', [SalesOpportunityController::class, 'storeActivity'])
    ->name('sales.opportunities.activities.store')
    ->middleware(CheckAbilities::class.':sales.update');

Route::post('sales/opportunities/{opportunity}/read', [SalesOpportunityController::class, 'markRead'])
    ->name('sales.opportunities.read')
    ->middleware(CheckAbilities::class.':sales.update');

Route::get('sales/quote-template-catalog', [SalesQuoteTemplateWorkflowController::class, 'catalog'])
    ->name('sales.quote-templates.catalog')
    ->middleware(CheckAbilities::class.':sales.quote_templates.read');

Route::get('sales/quote-templates', [SalesQuoteTemplateWorkflowController::class, 'index'])
    ->name('sales.quote-templates.index')
    ->middleware(CheckAbilities::class.':sales.quote_templates.read');

Route::post('sales/quote-templates', [SalesQuoteTemplateWorkflowController::class, 'store'])
    ->name('sales.quote-templates.store')
    ->middleware(CheckAbilities::class.':sales.quote_templates.manage');

Route::get('sales/quote-templates/{template}', [SalesQuoteTemplateWorkflowController::class, 'show'])
    ->name('sales.quote-templates.show')
    ->middleware(CheckAbilities::class.':sales.quote_templates.read');

Route::put('sales/quote-templates/{template}', [SalesQuoteTemplateWorkflowController::class, 'update'])
    ->name('sales.quote-templates.update')
    ->middleware(CheckAbilities::class.':sales.quote_templates.manage');

Route::delete('sales/quote-templates/{template}', [SalesQuoteTemplateWorkflowController::class, 'destroy'])
    ->name('sales.quote-templates.destroy')
    ->middleware(CheckAbilities::class.':sales.quote_templates.manage');

Route::post('sales/quote-templates/{template}/lines', [SalesQuoteTemplateWorkflowController::class, 'storeLine'])
    ->name('sales.quote-templates.lines.store')
    ->middleware(CheckAbilities::class.':sales.quote_templates.manage');

Route::delete('sales/quote-templates/{template}/lines/{line}', [SalesQuoteTemplateWorkflowController::class, 'destroyLine'])
    ->name('sales.quote-templates.lines.destroy')
    ->middleware(CheckAbilities::class.':sales.quote_templates.manage');

Route::post('sales/quote-templates/{template}/acknowledgements', [SalesQuoteTemplateWorkflowController::class, 'storeAcknowledgement'])
    ->name('sales.quote-templates.acknowledgements.store')
    ->middleware(CheckAbilities::class.':sales.quote_templates.manage');

Route::delete('sales/quote-templates/{template}/acknowledgements/{acknowledgement}', [SalesQuoteTemplateWorkflowController::class, 'destroyAcknowledgement'])
    ->name('sales.quote-templates.acknowledgements.destroy')
    ->middleware(CheckAbilities::class.':sales.quote_templates.manage');
