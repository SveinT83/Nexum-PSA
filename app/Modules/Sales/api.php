<?php

use App\Modules\Sales\Controllers\Api\V1\SalesOpportunityController;
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

Route::post('sales/opportunities/{opportunity}/activities', [SalesOpportunityController::class, 'storeActivity'])
    ->name('sales.opportunities.activities.store')
    ->middleware(CheckAbilities::class.':sales.update');

Route::post('sales/opportunities/{opportunity}/read', [SalesOpportunityController::class, 'markRead'])
    ->name('sales.opportunities.read')
    ->middleware(CheckAbilities::class.':sales.update');
