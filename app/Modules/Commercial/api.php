<?php

use App\Modules\Commercial\Controllers\Api\V1\CommercialController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('commercial/services', [CommercialController::class, 'services'])
    ->name('commercial.services.index')
    ->middleware(CheckAbilities::class.':commercial.read');
Route::post('commercial/services', [CommercialController::class, 'storeService'])
    ->name('commercial.services.store')
    ->middleware(CheckAbilities::class.':commercial.create');
Route::get('commercial/services/{service}', [CommercialController::class, 'showService'])
    ->name('commercial.services.show')
    ->middleware(CheckAbilities::class.':commercial.read');
Route::match(['put', 'patch'], 'commercial/services/{service}', [CommercialController::class, 'updateService'])
    ->name('commercial.services.update')
    ->middleware(CheckAbilities::class.':commercial.update');

Route::get('commercial/contracts', [CommercialController::class, 'contracts'])
    ->name('commercial.contracts.index')
    ->middleware(CheckAbilities::class.':commercial.read');
Route::post('commercial/contracts', [CommercialController::class, 'storeContract'])
    ->name('commercial.contracts.store')
    ->middleware(CheckAbilities::class.':commercial.create');
Route::get('commercial/contracts/{contract}', [CommercialController::class, 'showContract'])
    ->name('commercial.contracts.show')
    ->middleware(CheckAbilities::class.':commercial.read');
Route::match(['put', 'patch'], 'commercial/contracts/{contract}', [CommercialController::class, 'updateContract'])
    ->name('commercial.contracts.update')
    ->middleware(CheckAbilities::class.':commercial.update');

Route::get('commercial/slas', [CommercialController::class, 'slas'])
    ->name('commercial.slas.index')
    ->middleware(CheckAbilities::class.':commercial.read');
Route::post('commercial/slas', [CommercialController::class, 'storeSla'])
    ->name('commercial.slas.store')
    ->middleware(CheckAbilities::class.':commercial.create');
Route::get('commercial/slas/{sla}', [CommercialController::class, 'showSla'])
    ->name('commercial.slas.show')
    ->middleware(CheckAbilities::class.':commercial.read');
Route::match(['put', 'patch'], 'commercial/slas/{sla}', [CommercialController::class, 'updateSla'])
    ->name('commercial.slas.update')
    ->middleware(CheckAbilities::class.':commercial.update');

Route::get('commercial/time-rates', [CommercialController::class, 'timeRates'])
    ->name('commercial.time-rates.index')
    ->middleware(CheckAbilities::class.':commercial.read');
Route::post('commercial/time-rates', [CommercialController::class, 'storeTimeRate'])
    ->name('commercial.time-rates.store')
    ->middleware(CheckAbilities::class.':commercial.create');
Route::get('commercial/time-rates/{rate}', [CommercialController::class, 'showTimeRate'])
    ->name('commercial.time-rates.show')
    ->middleware(CheckAbilities::class.':commercial.read');
Route::match(['put', 'patch'], 'commercial/time-rates/{rate}', [CommercialController::class, 'updateTimeRate'])
    ->name('commercial.time-rates.update')
    ->middleware(CheckAbilities::class.':commercial.update');
