<?php

use App\Modules\Asset\Controllers\Api\V1\AssetController as ApiAssetController;
use App\Modules\Asset\Controllers\Admin\AssetSettingsController;
use App\Modules\Asset\Controllers\Tech\AssetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Asset Module Routes
|--------------------------------------------------------------------------
|
| These routes are loaded inside the authenticated /tech route group. Route
| names intentionally remain unchanged (`tech.assets.*` and
| `tech.clients.assets.index`) so existing menus, bookmarks, and Blade links do
| not break while the implementation moves into the singular Asset module.
|
*/

if (isset($tdpsaLoadingApiRoutes) && $tdpsaLoadingApiRoutes === true) {
    /*
    |--------------------------------------------------------------------------
    | Asset API Routes
    |--------------------------------------------------------------------------
    |
    | routes/api.php loads this same module route file with
    | `$tdpsaLoadingApiRoutes = true`, so Asset keeps every route in its required
    | `routes.php` file while still registering API endpoints under `/api/v1`.
    |
    */
    Route::apiResource('assets', ApiAssetController::class)->only(['index', 'show']);

    return;
}

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/assets', [AssetSettingsController::class, 'edit'])
        ->name('admin.settings.assets');

    Route::put('/admin/settings/assets', [AssetSettingsController::class, 'update'])
        ->name('admin.settings.assets.update');
});

Route::get('/assets/docs', [AssetController::class, 'docs'])
    ->name('assets.docs');

Route::get('/assets', [AssetController::class, 'index'])
    ->name('assets.index');

Route::get('/assets/create', [AssetController::class, 'create'])
    ->name('assets.create');

Route::post('/assets/store', [AssetController::class, 'store'])
    ->name('assets.store');

Route::get('/assets/edit/{asset}', [AssetController::class, 'edit'])
    ->name('assets.edit');

Route::get('/assets/{asset}/{tab?}', [AssetController::class, 'show'])
    ->name('assets.show');

Route::put('/assets/update/{asset}', [AssetController::class, 'update'])
    ->name('assets.update');

// Canonical client-scoped asset list. This preserves the route name that the
// Clients module menu already uses while keeping ownership in the Asset module.
Route::get('/clients/{client}/assets', [AssetController::class, 'index'])
    ->name('clients.assets.index');

// Legacy URL kept for compatibility with older links. It calls the same
// controller but uses a distinct name so the canonical named route remains
// `/tech/clients/{client}/assets`.
Route::get('/clients/assets/{client?}', [AssetController::class, 'index'])
    ->name('clients.assets.legacy');
