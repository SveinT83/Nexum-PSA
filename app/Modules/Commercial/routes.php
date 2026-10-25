<?php

use App\Modules\Commercial\Controllers\Admin\ClientTimebankPolicyController;
use App\Modules\Commercial\Controllers\Admin\TimeRateController;
use App\Modules\Commercial\Controllers\Admin\UnitsController;
use App\Modules\Commercial\Controllers\Portal\PortalContractController;
use App\Modules\Commercial\Controllers\Tech\Contracts\ClientTimebankConsumptionController;
use App\Modules\Commercial\Controllers\Tech\Contracts\ContractController;
use App\Modules\Commercial\Controllers\Tech\Contracts\PublicContractController;
use App\Modules\Commercial\Controllers\Tech\Costs\CostController;
use App\Modules\Commercial\Controllers\Tech\Legal\LegalController;
use App\Modules\Commercial\Controllers\Tech\Package\PackageController;
use App\Modules\Commercial\Controllers\Tech\Rates\TimeRateController as TechTimeRateController;
use App\Modules\Commercial\Controllers\Tech\Services\ServiceController;
use App\Modules\Commercial\Controllers\Tech\Sla\SlaController;
use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

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

if (($commercialPublicApiRoutes ?? false) === true) {
    Route::middleware([\App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class, 'throttle:60,1'])->group(function () {
        Route::get('commercial/public/contracts/{token}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCustomerContractController::class, 'show'])->name('commercial.public.contracts.show');
        Route::post('commercial/public/contracts/{token}/viewed', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCustomerContractController::class, 'viewed'])->name('commercial.public.contracts.viewed');
        Route::post('commercial/public/contracts/{token}/accept', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCustomerContractController::class, 'accept'])->name('commercial.public.contracts.accept');
    });
    Route::middleware(['web', 'auth', EnsureCustomerPortalAccess::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class, 'throttle:60,1'])->group(function () {
        Route::get('commercial/portal/contracts', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCustomerContractController::class, 'index'])->name('commercial.portal.contracts.index');
        Route::get('commercial/portal/contracts/{contract}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCustomerContractController::class, 'show'])->name('commercial.portal.contracts.show');
        Route::post('commercial/portal/contracts/{contract}/accept', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCustomerContractController::class, 'accept'])->name('commercial.portal.contracts.accept');
    });

    return;
}

if (($commercialApiRoutes ?? false) === true) {
    Route::post('commercial/contracts/{contract}/pricing-preview', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'previewPricing'])->name('commercial.contracts.pricing-preview')->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/services', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'index'])->name('commercial.services.index')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.service_manage')->defaults('commercial_kind', 'services')->defaults('commercial_parameter', 'service')->defaults('commercial_new', false);
    Route::get('commercial/services/context', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'context'])->name('commercial.services.context')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.service_manage')->defaults('commercial_kind', 'services')->defaults('commercial_parameter', 'service')->defaults('commercial_new', false);
    Route::get('commercial/services/context/{lookup}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'lookup'])->name('commercial.services.lookup')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.service_manage')->defaults('commercial_kind', 'services')->defaults('commercial_parameter', 'service')->defaults('commercial_new', false);
    Route::post('commercial/services', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'store'])->name('commercial.services.store')
        ->middleware([CheckAbilities::class.':commercial.create', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.service_manage')->defaults('commercial_kind', 'services')->defaults('commercial_parameter', 'service')->defaults('commercial_new', false);
    Route::post('commercial/services/pricing-preview', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'previewPricing'])->name('commercial.services.pricing-preview')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.service_manage')->defaults('commercial_kind', 'services')->defaults('commercial_parameter', 'service')->defaults('commercial_new', false);
    Route::get('commercial/services/{service}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'show'])->name('commercial.services.show')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.service_manage')->defaults('commercial_kind', 'services')->defaults('commercial_parameter', 'service')->defaults('commercial_new', false);
    Route::match(['put', 'patch'], 'commercial/services/{service}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'update'])->name('commercial.services.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.service_manage')->defaults('commercial_kind', 'services')->defaults('commercial_parameter', 'service')->defaults('commercial_new', false);
    Route::delete('commercial/services/{service}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'destroy'])->name('commercial.services.destroy')
        ->middleware([CheckAbilities::class.':commercial.delete', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.service_manage')->defaults('commercial_kind', 'services')->defaults('commercial_parameter', 'service')->defaults('commercial_new', false);
    Route::get('commercial/packages', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'index'])->name('commercial.packages.index')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.package_manage')->defaults('commercial_kind', 'packages')->defaults('commercial_parameter', 'package')->defaults('commercial_new', true);
    Route::get('commercial/packages/context', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'context'])->name('commercial.packages.context')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.package_manage')->defaults('commercial_kind', 'packages')->defaults('commercial_parameter', 'package')->defaults('commercial_new', true);
    Route::get('commercial/packages/context/{lookup}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'lookup'])->name('commercial.packages.lookup')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.package_manage')->defaults('commercial_kind', 'packages')->defaults('commercial_parameter', 'package')->defaults('commercial_new', true);
    Route::post('commercial/packages', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'store'])->name('commercial.packages.store')
        ->middleware([CheckAbilities::class.':commercial.create', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.package_manage')->defaults('commercial_kind', 'packages')->defaults('commercial_parameter', 'package')->defaults('commercial_new', true);
    Route::post('commercial/packages/pricing-preview', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'previewPricing'])->name('commercial.packages.pricing-preview')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.package_manage')->defaults('commercial_kind', 'packages')->defaults('commercial_parameter', 'package')->defaults('commercial_new', true);
    Route::get('commercial/packages/{package}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'show'])->name('commercial.packages.show')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.package_manage')->defaults('commercial_kind', 'packages')->defaults('commercial_parameter', 'package')->defaults('commercial_new', true);
    Route::match(['put', 'patch'], 'commercial/packages/{package}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'update'])->name('commercial.packages.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.package_manage')->defaults('commercial_kind', 'packages')->defaults('commercial_parameter', 'package')->defaults('commercial_new', true);
    Route::delete('commercial/packages/{package}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'destroy'])->name('commercial.packages.destroy')
        ->middleware([CheckAbilities::class.':commercial.delete', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.package_manage')->defaults('commercial_kind', 'packages')->defaults('commercial_parameter', 'package')->defaults('commercial_new', true);
    Route::get('commercial/costs', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'index'])->name('commercial.costs.index')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.cost_manage')->defaults('commercial_kind', 'costs')->defaults('commercial_parameter', 'cost')->defaults('commercial_new', true);
    Route::get('commercial/costs/context', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'context'])->name('commercial.costs.context')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.cost_manage')->defaults('commercial_kind', 'costs')->defaults('commercial_parameter', 'cost')->defaults('commercial_new', true);
    Route::get('commercial/costs/context/{lookup}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'lookup'])->name('commercial.costs.lookup')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.cost_manage')->defaults('commercial_kind', 'costs')->defaults('commercial_parameter', 'cost')->defaults('commercial_new', true);
    Route::post('commercial/costs', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'store'])->name('commercial.costs.store')
        ->middleware([CheckAbilities::class.':commercial.create', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.cost_manage')->defaults('commercial_kind', 'costs')->defaults('commercial_parameter', 'cost')->defaults('commercial_new', true);
    Route::get('commercial/costs/{cost}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'show'])->name('commercial.costs.show')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.cost_manage')->defaults('commercial_kind', 'costs')->defaults('commercial_parameter', 'cost')->defaults('commercial_new', true);
    Route::match(['put', 'patch'], 'commercial/costs/{cost}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'update'])->name('commercial.costs.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.cost_manage')->defaults('commercial_kind', 'costs')->defaults('commercial_parameter', 'cost')->defaults('commercial_new', true);
    Route::delete('commercial/costs/{cost}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'destroy'])->name('commercial.costs.destroy')
        ->middleware([CheckAbilities::class.':commercial.delete', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.cost_manage')->defaults('commercial_kind', 'costs')->defaults('commercial_parameter', 'cost')->defaults('commercial_new', true);
    Route::get('commercial/terms', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'index'])->name('commercial.terms.index')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_kind', 'terms')->defaults('commercial_parameter', 'term')->defaults('commercial_new', true);
    Route::get('commercial/terms/context', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'context'])->name('commercial.terms.context')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_kind', 'terms')->defaults('commercial_parameter', 'term')->defaults('commercial_new', true);
    Route::get('commercial/terms/context/{lookup}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'lookup'])->name('commercial.terms.lookup')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_kind', 'terms')->defaults('commercial_parameter', 'term')->defaults('commercial_new', true);
    Route::post('commercial/terms', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'store'])->name('commercial.terms.store')
        ->middleware([CheckAbilities::class.':commercial.create', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_kind', 'terms')->defaults('commercial_parameter', 'term')->defaults('commercial_new', true);
    Route::get('commercial/terms/{term}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'show'])->name('commercial.terms.show')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_kind', 'terms')->defaults('commercial_parameter', 'term')->defaults('commercial_new', true);
    Route::match(['put', 'patch'], 'commercial/terms/{term}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'update'])->name('commercial.terms.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_kind', 'terms')->defaults('commercial_parameter', 'term')->defaults('commercial_new', true);
    Route::delete('commercial/terms/{term}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'destroy'])->name('commercial.terms.destroy')
        ->middleware([CheckAbilities::class.':commercial.delete', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_kind', 'terms')->defaults('commercial_parameter', 'term')->defaults('commercial_new', true);
    Route::get('commercial/slas', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'index'])->name('commercial.slas.index')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.sla_manage')->defaults('commercial_kind', 'slas')->defaults('commercial_parameter', 'sla')->defaults('commercial_new', false);
    Route::get('commercial/slas/context', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'context'])->name('commercial.slas.context')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.sla_manage')->defaults('commercial_kind', 'slas')->defaults('commercial_parameter', 'sla')->defaults('commercial_new', false);
    Route::get('commercial/slas/context/{lookup}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'lookup'])->name('commercial.slas.lookup')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.sla_manage')->defaults('commercial_kind', 'slas')->defaults('commercial_parameter', 'sla')->defaults('commercial_new', false);
    Route::post('commercial/slas', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'store'])->name('commercial.slas.store')
        ->middleware([CheckAbilities::class.':commercial.create', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.sla_manage')->defaults('commercial_kind', 'slas')->defaults('commercial_parameter', 'sla')->defaults('commercial_new', false);
    Route::get('commercial/slas/{sla}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'show'])->name('commercial.slas.show')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.sla_manage')->defaults('commercial_kind', 'slas')->defaults('commercial_parameter', 'sla')->defaults('commercial_new', false);
    Route::match(['put', 'patch'], 'commercial/slas/{sla}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'update'])->name('commercial.slas.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.sla_manage')->defaults('commercial_kind', 'slas')->defaults('commercial_parameter', 'sla')->defaults('commercial_new', false);
    Route::get('commercial/time-rates', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'index'])->name('commercial.time-rates.index')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.rate_manage')->defaults('commercial_kind', 'time-rates')->defaults('commercial_parameter', 'rate')->defaults('commercial_new', false);
    Route::get('commercial/time-rates/context', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'context'])->name('commercial.time-rates.context')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.rate_manage')->defaults('commercial_kind', 'time-rates')->defaults('commercial_parameter', 'rate')->defaults('commercial_new', false);
    Route::get('commercial/time-rates/context/{lookup}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'lookup'])->name('commercial.time-rates.lookup')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.rate_manage')->defaults('commercial_kind', 'time-rates')->defaults('commercial_parameter', 'rate')->defaults('commercial_new', false);
    Route::post('commercial/time-rates', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'store'])->name('commercial.time-rates.store')
        ->middleware([CheckAbilities::class.':commercial.create', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.rate_manage')->defaults('commercial_kind', 'time-rates')->defaults('commercial_parameter', 'rate')->defaults('commercial_new', false);
    Route::get('commercial/time-rates/{rate}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'show'])->name('commercial.time-rates.show')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.rate_manage')->defaults('commercial_kind', 'time-rates')->defaults('commercial_parameter', 'rate')->defaults('commercial_new', false);
    Route::match(['put', 'patch'], 'commercial/time-rates/{rate}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'update'])->name('commercial.time-rates.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.rate_manage')->defaults('commercial_kind', 'time-rates')->defaults('commercial_parameter', 'rate')->defaults('commercial_new', false);
    Route::get('commercial/units', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'index'])->name('commercial.units.index')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'economy.manage_settings')->defaults('commercial_kind', 'units')->defaults('commercial_parameter', 'unit')->defaults('commercial_new', true);
    Route::get('commercial/units/context', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'context'])->name('commercial.units.context')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'economy.manage_settings')->defaults('commercial_kind', 'units')->defaults('commercial_parameter', 'unit')->defaults('commercial_new', true);
    Route::get('commercial/units/context/{lookup}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'lookup'])->name('commercial.units.lookup')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'economy.manage_settings')->defaults('commercial_kind', 'units')->defaults('commercial_parameter', 'unit')->defaults('commercial_new', true);
    Route::post('commercial/units', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'store'])->name('commercial.units.store')
        ->middleware([CheckAbilities::class.':commercial.create', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'economy.manage_settings')->defaults('commercial_kind', 'units')->defaults('commercial_parameter', 'unit')->defaults('commercial_new', true);
    Route::get('commercial/units/{unit}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'show'])->name('commercial.units.show')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'economy.manage_settings')->defaults('commercial_kind', 'units')->defaults('commercial_parameter', 'unit')->defaults('commercial_new', true);
    Route::match(['put', 'patch'], 'commercial/units/{unit}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'update'])->name('commercial.units.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'economy.manage_settings')->defaults('commercial_kind', 'units')->defaults('commercial_parameter', 'unit')->defaults('commercial_new', true);
    Route::delete('commercial/units/{unit}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCatalogueController::class, 'destroy'])->name('commercial.units.destroy')
        ->middleware([CheckAbilities::class.':commercial.delete', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'economy.manage_settings')->defaults('commercial_kind', 'units')->defaults('commercial_parameter', 'unit')->defaults('commercial_new', true);
    Route::get('commercial/contracts', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'index'])->name('commercial.contracts.index')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/contracts/context', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'context'])->name('commercial.contracts.context')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/contracts/context/{lookup}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'lookup'])->name('commercial.contracts.lookup')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::post('commercial/contracts', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'store'])->name('commercial.contracts.store')
        ->middleware([CheckAbilities::class.':commercial.create', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/contracts/{contract}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'show'])->name('commercial.contracts.show')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::match(['put', 'patch'], 'commercial/contracts/{contract}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'update'])->name('commercial.contracts.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::delete('commercial/contracts/{contract}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'destroy'])->name('commercial.contracts.destroy')
        ->middleware([CheckAbilities::class.':commercial.delete', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/contracts/{contract}/items', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'items'])->name('commercial.contracts.items')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/contracts/{contract}/item-defaults', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'defaults'])->name('commercial.contracts.item-defaults')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::post('commercial/contracts/{contract}/items', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'saveItem'])->name('commercial.contracts.items.store')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::match(['put', 'patch'], 'commercial/contracts/{contract}/items/{item}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'saveItem'])->name('commercial.contracts.items.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::delete('commercial/contracts/{contract}/items/{item}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'removeItem'])->name('commercial.contracts.items.destroy')
        ->middleware([CheckAbilities::class.':commercial.delete', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::post('commercial/contracts/{contract}/items/{item}/sync-cadence', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'syncCadence'])->name('commercial.contracts.items.sync-cadence')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/contracts/{contract}/pricing', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'pricing'])->name('commercial.contracts.pricing')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/contracts/{contract}/terms', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'terms'])->name('commercial.contracts.terms')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::match(['put', 'patch'], 'commercial/contracts/{contract}/terms', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'saveTerms'])->name('commercial.contracts.terms.update')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::post('commercial/contracts/{contract}/terms/refresh', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'refreshTerms'])->name('commercial.contracts.terms.refresh')
        ->middleware([CheckAbilities::class.':commercial.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::post('commercial/contracts/{contract}/approve-manual', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'approve'])->name('commercial.contracts.approve-manual')
        ->middleware([CheckAbilities::class.':commercial.approve', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/contracts/{contract}/customer-document/attestation-preview', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'attestationPreview'])->name('commercial.contracts.customer-document.attestation-preview')
        ->middleware([CheckAbilities::class.':commercial.approve', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::post('commercial/contracts/{contract}/customer-document/attest-legacy', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'attest'])->name('commercial.contracts.customer-document.attest-legacy')
        ->middleware([CheckAbilities::class.':commercial.approve', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/contracts/{contract}/pdf', [\App\Modules\Commercial\Controllers\Api\V1\CommercialContractController::class, 'pdf'])->name('commercial.contracts.pdf')
        ->middleware([CheckAbilities::class.':commercial.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::post('commercial/contracts/{contract}/communication-preview', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCommunicationController::class, 'preview'])->name('commercial.contracts.communication-preview')
        ->middleware([CheckAbilities::class.':commercial.send', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::post('commercial/contracts/{contract}/send-quote', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCommunicationController::class, 'submit'])->name('commercial.contracts.send-quote')
        ->middleware([CheckAbilities::class.':commercial.send', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_delivery_kind', 'quote');
    Route::post('commercial/contracts/{contract}/send-contract', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCommunicationController::class, 'submit'])->name('commercial.contracts.send-contract')
        ->middleware([CheckAbilities::class.':commercial.send', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_delivery_kind', 'contract');
    Route::post('commercial/contracts/{contract}/resend', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCommunicationController::class, 'submit'])->name('commercial.contracts.resend')
        ->middleware([CheckAbilities::class.':commercial.send', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage')->defaults('commercial_delivery_kind', 'resend');
    Route::get('commercial/contracts/{contract}/deliveries/{delivery}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCommunicationController::class, 'show'])->name('commercial.contracts.deliveries.show')
        ->middleware([CheckAbilities::class.':commercial.send', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::post('commercial/contracts/{contract}/deliveries/{delivery}/recover', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCommunicationController::class, 'recover'])->name('commercial.contracts.deliveries.recover')
        ->middleware([CheckAbilities::class.':commercial.send', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/operations/{operation}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialCommunicationController::class, 'operation'])->name('commercial.operations.show')
        ->middleware([CheckAbilities::class.':commercial.operations.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.view|commercial.contract_manage|commercial.service_manage|commercial.package_manage|commercial.cost_manage|commercial.sla_manage|commercial.rate_manage|economy.manage_settings|client.view|commercial.timebank.quick-consume');
    Route::get('commercial/clients/{client}/timebank', [\App\Modules\Commercial\Controllers\Api\V1\CommercialTimebankController::class, 'context'])->name('commercial.clients.timebank')
        ->middleware([CheckAbilities::class.':commercial.timebank.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'client.view');
    Route::get('commercial/clients/{client}/timebank/consumptions/{consumption}', [\App\Modules\Commercial\Controllers\Api\V1\CommercialTimebankController::class, 'show'])->name('commercial.clients.timebank.consumptions.show')
        ->middleware([CheckAbilities::class.':commercial.timebank.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'client.view');
    Route::post('commercial/clients/{client}/timebank/consumptions', [\App\Modules\Commercial\Controllers\Api\V1\CommercialTimebankController::class, 'store'])->name('commercial.clients.timebank.consumptions.store')
        ->middleware([CheckAbilities::class.':commercial.timebank.consume', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.timebank.quick-consume');
    Route::get('commercial/settings', [\App\Modules\Commercial\Controllers\Api\V1\CommercialTimebankController::class, 'settings'])->name('commercial.settings')
        ->middleware([CheckAbilities::class.':commercial.settings.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.view');
    Route::get('commercial/settings/timebank-policy', [\App\Modules\Commercial\Controllers\Api\V1\CommercialTimebankController::class, 'policy'])->name('commercial.settings.timebank-policy')
        ->middleware([CheckAbilities::class.':commercial.settings.read', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::match(['put', 'patch'], 'commercial/settings/timebank-policy', [\App\Modules\Commercial\Controllers\Api\V1\CommercialTimebankController::class, 'updatePolicy'])->name('commercial.settings.timebank-policy.update')
        ->middleware([CheckAbilities::class.':commercial.settings.update', \App\Modules\Commercial\Http\Middleware\CommercialApiGuard::class, \App\Modules\Commercial\Http\Middleware\CommercialApiErrors::class])->defaults('commercial_permission', 'commercial.contract_manage');
    Route::get('commercial/worklog/time-consumptions', [\App\Modules\Commercial\Controllers\Api\V1\CommercialWorklogController::class, 'consumptions'])
        ->name('commercial.worklog.time-consumptions.index')
        ->middleware(\App\Modules\Integration\Http\Middleware\EnforceCoordinatorWorkload::class.':pseudonymized,commercial.worklog.read');
    Route::get('commercial/worklog/contract-links', [\App\Modules\Commercial\Controllers\Api\V1\CommercialWorklogController::class, 'contractLinks'])
        ->name('commercial.worklog.contract-links.index')
        ->middleware(\App\Modules\Integration\Http\Middleware\EnforceCoordinatorWorkload::class.':pseudonymized,commercial.worklog-links.read');

    return;
}

if (($commercialCoordinatorApiRoutes ?? false) === true) {
    Route::get('commercial/worklog/time-consumptions', [\App\Modules\Commercial\Controllers\Api\V1\CommercialWorklogController::class, 'consumptions'])
        ->name('commercial.worklog.time-consumptions.index')
        ->middleware(\App\Modules\Integration\Http\Middleware\EnforceCoordinatorWorkload::class.':pseudonymized,commercial.worklog.read');
    Route::get('commercial/worklog/contract-links', [\App\Modules\Commercial\Controllers\Api\V1\CommercialWorklogController::class, 'contractLinks'])
        ->name('commercial.worklog.contract-links.index')
        ->middleware(\App\Modules\Integration\Http\Middleware\EnforceCoordinatorWorkload::class.':pseudonymized,commercial.worklog-links.read');

    return;
}

if (($commercialPortalRoutes ?? false) === true) {
    Route::middleware(['auth', EnsureCustomerPortalAccess::class])
        ->prefix('portal/contracts')
        ->name('customer-portal.contracts.')
        ->group(function (): void {
            Route::get('/', [PortalContractController::class, 'index'])->name('index');
            Route::get('/{contract}', [PortalContractController::class, 'show'])->name('show');
            Route::post('/{contract}/accept', [PortalContractController::class, 'accept'])->name('accept');
        });

    return;
}

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
Route::get('/contracts/{contract}/pdf', [ContractController::class, 'pdf'])
    ->name('contracts.pdf');
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
Route::post('/contracts/{contract}/customer-document/attest-legacy', [ContractController::class, 'attestLegacyCustomerDocument'])
    ->name('contracts.customer-document.attest-legacy');
Route::post('/contracts/{contract}/approve-manual', [ContractController::class, 'approveManual'])
    ->name('contracts.approve-manual');
Route::get('/contracts/{contract}/services', [ContractController::class, 'servicesEdit'])
    ->name('contracts.services.edit');
Route::post('/contracts/{contract}/services', [ContractController::class, 'servicesUpdate'])
    ->name('contracts.services.update');
Route::get('/contracts/{contract}/terms', [ContractController::class, 'terms'])
    ->name('contracts.terms');
Route::post('/contracts/{contract}/terms/refresh', [ContractController::class, 'termsRefresh'])
    ->name('contracts.terms.refresh');
Route::post('/contracts/{contract}/terms', [ContractController::class, 'termsUpdate'])
    ->name('contracts.terms.update');
Route::post('/clients/{client}/contracts/timebank-consumptions', [ClientTimebankConsumptionController::class, 'store'])
    ->name('clients.contracts.timebank-consumptions.store');

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
Route::delete('/costs/delete/{cost}', [CostController::class, 'delete'])
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
// No SLA deletion is implemented; do not expose a dead action.

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/cs/timebank-policy', [ClientTimebankPolicyController::class, 'edit'])
        ->name('admin.settings.cs.timebank-policy');
    Route::put('/admin/settings/cs/timebank-policy', [ClientTimebankPolicyController::class, 'update'])
        ->name('admin.settings.cs.timebank-policy.update');
    Route::get('/admin/settings/economy/units', [UnitsController::class, 'index'])
        ->name('admin.settings.economy.units');
    Route::post('/admin/settings/economy/units', [UnitsController::class, 'store'])
        ->name('admin.settings.economy.units.store');
    Route::post('/admin/settings/economy/units/update/{unit}', [UnitsController::class, 'update'])
        ->name('admin.settings.economy.units.update');
    Route::get('/admin/settings/economy/rates', [TimeRateController::class, 'index'])
        ->name('admin.settings.economy.rates');
    Route::post('/admin/settings/economy/rates', [TimeRateController::class, 'store'])
        ->name('admin.settings.economy.rates.store');
    Route::put('/admin/settings/economy/rates/{rate}', [TimeRateController::class, 'update'])
        ->name('admin.settings.economy.rates.update');

    // -----------------------------------------
    // Contracts & Services Settings
    // -----------------------------------------
    Route::redirect('/admin/settings/cs/contacts', '/tech/admin/settings/cs/contracts')
        ->name('admin.settings.cs.contracts.legacy');

    Route::get('/admin/settings/cs/contracts', function () {
        return view('commercial::Admin.settings.contracts');
    })->name('admin.settings.cs.contracts');

    Route::get('/admin/settings/cs/services', function () {
        return view('commercial::Admin.settings.services');
    })->name('admin.settings.cs.services');
});
