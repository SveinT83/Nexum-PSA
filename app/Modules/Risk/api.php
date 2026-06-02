<?php

use App\Modules\Risk\Controllers\Api\V1\RiskController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('risk/assessments', [RiskController::class, 'assessments'])
    ->name('risk.assessments.index')
    ->middleware(CheckAbilities::class.':risk.read');

Route::post('risk/assessments', [RiskController::class, 'storeAssessment'])
    ->name('risk.assessments.store')
    ->middleware(CheckAbilities::class.':risk.create');

Route::get('risk/assessments/{assessment}', [RiskController::class, 'showAssessment'])
    ->name('risk.assessments.show')
    ->middleware(CheckAbilities::class.':risk.read');

Route::match(['put', 'patch'], 'risk/assessments/{assessment}', [RiskController::class, 'updateAssessment'])
    ->name('risk.assessments.update')
    ->middleware(CheckAbilities::class.':risk.update');

Route::post('risk/assessments/{assessment}/items', [RiskController::class, 'storeItem'])
    ->name('risk.assessments.items.store')
    ->middleware(CheckAbilities::class.':risk.create');

Route::get('risk/items/{item}', [RiskController::class, 'showItem'])
    ->name('risk.items.show')
    ->middleware(CheckAbilities::class.':risk.read');

Route::match(['put', 'patch'], 'risk/items/{item}', [RiskController::class, 'updateItem'])
    ->name('risk.items.update')
    ->middleware(CheckAbilities::class.':risk.update');

Route::post('risk/items/{item}/updates', [RiskController::class, 'storeItemUpdate'])
    ->name('risk.items.updates.store')
    ->middleware(CheckAbilities::class.':risk.update');
