<?php
use App\Modules\Clients\Controllers\Api\V1\ClientController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::get('clients', [ClientController::class, 'index'])
    ->name('clients.index')
    ->middleware(CheckAbilities::class.':clients.read');

Route::post('clients', [ClientController::class, 'store'])
    ->name('clients.store')
    ->middleware(CheckAbilities::class.':clients.create');

Route::get('clients/{client}', [ClientController::class, 'show'])
    ->name('clients.show')
    ->middleware(CheckAbilities::class.':clients.read');

Route::match(['put', 'patch'], 'clients/{client}', [ClientController::class, 'update'])
    ->name('clients.update')
    ->middleware(CheckAbilities::class.':clients.update');

Route::get('clients/{client}/assets', [ClientController::class, 'assets'])
    ->middleware(CheckAbilities::class.':assets.read')
    ->name('clients.assets');

Route::get('clients/{client}/sites', [ClientController::class, 'sites'])
    ->middleware(CheckAbilities::class.':clients.read')
    ->name('clients.sites.index');

Route::post('clients/{client}/sites', [ClientController::class, 'storeSite'])
    ->middleware(CheckAbilities::class.':clients.update')
    ->name('clients.sites.store');

Route::match(['put', 'patch'], 'client-sites/{site}', [ClientController::class, 'updateSite'])
    ->middleware(CheckAbilities::class.':clients.update')
    ->name('client-sites.update');
