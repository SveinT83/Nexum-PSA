<?php

use App\Modules\Commercial\Controllers\Admin\TimeRateController;
use App\Modules\Commercial\Controllers\Admin\UnitsController;
use App\Modules\Commercial\Controllers\Tech\Contracts\ContractController;
use App\Modules\Commercial\Controllers\Tech\Contracts\PublicContractController;
use App\Modules\Commercial\Controllers\Tech\Costs\CostController;
use App\Modules\Commercial\Controllers\Tech\Legal\LegalController;
use App\Modules\Commercial\Controllers\Tech\Package\PackageController;
use App\Modules\Commercial\Controllers\Tech\Rates\TimeRateController as TechTimeRateController;
use App\Modules\Commercial\Controllers\Tech\Services\ServiceController;
use App\Modules\Commercial\Controllers\Tech\Sla\SlaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Commercial Module Routes
|--------------------------------------------------------------------------
|
| This file is loaded from two contexts:
| - routes/web.php with $commercialPublicRoutes=true for public contract links.
| - routes/tech.php inside the authenticated /tech group for Commercial UI.
|
*/

if (($commercialPublicRoutes ?? false) === true) {
    Route::get('/contract/view/{token}', [PublicContractController::class, 'view'])
        ->name('contracts.public.view');
    Route::post('/contract/accept/{token}', [PublicContractController::class, 'accept'])
        ->name('contracts.public.accept');

    return;
}

Route::get('/contracts', [ContractController::class, 'index'])
    ->name('contracts.index');
Route::get('/contracts/show/{contract}', [ContractController::class, 'show'])
    ->name('contracts.show');
Route::get('/contracts/create', [ContractController::class, 'create'])
    ->name('contracts.create');
Route::post('/contracts/store', [ContractController::class, 'store'])
    ->name('contracts.store');
Route::get('/contracts/edit/{contract}', [ContractController::class, 'edit'])
    ->name('contracts.edit');
Route::put('/contracts/update/{contract}', [ContractController::class, 'update'])
    ->name('contracts.update');
Route::delete('/contracts/delete/{contract}', [ContractController::class, 'destroy'])
    ->name('contracts.destroy');
Route::post('/contracts/{contract}/send-quote', [ContractController::class, 'sendQuote'])
    ->name('contracts.send-quote');
Route::post('/contracts/{contract}/send-contract', [ContractController::class, 'sendContract'])
    ->name('contracts.send-contract');
Route::post('/contracts/{contract}/resend', [ContractController::class, 'resend'])
    ->name('contracts.resend');
Route::post('/contracts/{contract}/approve-manual', [ContractController::class, 'approveManual'])
    ->name('contracts.approve-manual');
Route::get('/contracts/{contract}/services', [ContractController::class, 'servicesEdit'])
    ->name('contracts.services.edit');
Route::post('/contracts/{contract}/services', [ContractController::class, 'servicesUpdate'])
    ->name('contracts.services.update');
Route::get('/contracts/{contract}/terms', [ContractController::class, 'terms'])
    ->name('contracts.terms');
Route::post('/contracts/{contract}/terms', [ContractController::class, 'termsUpdate'])
    ->name('contracts.terms.update');

Route::get('/packages', [PackageController::class, 'index'])
    ->name('packages.index');
Route::get('/package/create', [PackageController::class, 'create'])
    ->name('packages.create');
Route::post('/package/store', [PackageController::class, 'store'])
    ->name('packages.store');
Route::get('/package/show/{package}', [PackageController::class, 'show'])
    ->name('packages.show');
Route::get('/package/edit/{package}', [PackageController::class, 'edit'])
    ->name('packages.edit');
Route::put('/package/update/{package}', [PackageController::class, 'update'])
    ->name('packages.update');
Route::delete('/package/delete/{package}', [PackageController::class, 'destroy'])
    ->name('packages.delete');

Route::get('/services', [ServiceController::class, 'index'])
    ->name('services.index');
Route::post('/services', [ServiceController::class, 'store'])
    ->name('services.store');
Route::post('/services/update/{service}', [ServiceController::class, 'update'])
    ->name('services.update');
Route::get('/services/create', [ServiceController::class, 'create'])
    ->name('services.create');
Route::get('/services/{service}', [ServiceController::class, 'show'])
    ->name('services.show');
Route::get('/services/edit/{service}', [ServiceController::class, 'edit'])
    ->name('services.edit');
Route::delete('/services/destroy/{service}', [ServiceController::class, 'destroy'])
    ->name('services.destroy');

Route::get('/rates', [TechTimeRateController::class, 'index'])
    ->name('rates.index');
Route::post('/rates', [TechTimeRateController::class, 'store'])
    ->name('rates.store');
Route::put('/rates/{rate}', [TechTimeRateController::class, 'update'])
    ->name('rates.update');

Route::get('/costs', [CostController::class, 'index'])
    ->name('costs.index');
Route::get('/costs/delete/{cost}', [CostController::class, 'delete'])
    ->name('costs.delete');
Route::post('/costs/update/{cost}', [CostController::class, 'update'])
    ->name('costs.update');
Route::get('/costs/create', [CostController::class, 'create'])
    ->name('costs.create');
Route::post('/costs/store', [CostController::class, 'store'])
    ->name('costs.store');
Route::get('/costs/{cost}', [CostController::class, 'show'])
    ->name('costs.show');
Route::get('/costs/edit/{cost}', [CostController::class, 'edit'])
    ->name('costs.edit');

Route::get('/legal', [LegalController::class, 'index'])
    ->name('legal.index');
Route::get('/legal/show/{term}', [LegalController::class, 'show'])
    ->name('legal.show');
Route::get('/legal/create', [LegalController::class, 'create'])
    ->name('legal.create');
Route::post('/legal/store', [LegalController::class, 'store'])
    ->name('legal.store');
Route::get('/legal/edit/{term}', [LegalController::class, 'edit'])
    ->name('legal.edit');
Route::put('/legal/update/{term}', [LegalController::class, 'update'])
    ->name('legal.update');
Route::delete('/legal/delete/{term}', [LegalController::class, 'delete'])
    ->name('legal.delete');

Route::get('/sla', [SlaController::class, 'index'])
    ->name('sla.index');
Route::get('/sla/show/{sla}', [SlaController::class, 'show'])
    ->name('sla.show');
Route::get('/sla/edit/{sla}', [SlaController::class, 'show'])
    ->name('sla.edit');
Route::get('/sla/create', [SlaController::class, 'create'])
    ->name('sla.create');
Route::post('/sla/store', [SlaController::class, 'store'])
    ->name('sla.store');
Route::put('/sla/update/{sla}', [SlaController::class, 'update'])
    ->name('sla.update');
Route::delete('/sla/delete/{sla}', [SlaController::class, 'destroy'])
    ->name('sla.delete');

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/economy/units', [UnitsController::class, 'index'])
        ->name('admin.settings.economy.units');
    Route::get('/admin/settings/economy/units/store', [UnitsController::class, 'store'])
        ->name('admin.settings.economy.units.store');
    Route::post('/admin/settings/economy/units/update/{unit}', [UnitsController::class, 'update'])
        ->name('admin.settings.economy.units.update');
    Route::get('/admin/settings/economy/rates', [TimeRateController::class, 'index'])
        ->name('admin.settings.economy.rates');
    Route::post('/admin/settings/economy/rates', [TimeRateController::class, 'store'])
        ->name('admin.settings.economy.rates.store');
    Route::put('/admin/settings/economy/rates/{rate}', [TimeRateController::class, 'update'])
        ->name('admin.settings.economy.rates.update');
});
