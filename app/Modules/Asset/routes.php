<?php

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
    Route::get('assets/context', [\App\Modules\Asset\Controllers\Api\V1\AssetWorkspaceController::class, 'context'])
        ->name('assets.context')->defaults('asset_permissions', ['asset.view'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.read', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::get('assets/lookups/{kind}', [\App\Modules\Asset\Controllers\Api\V1\AssetWorkspaceController::class, 'lookups'])
        ->name('assets.lookups')->defaults('asset_permissions', ['asset.view'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.read', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::get('assets/docs', [\App\Modules\Asset\Controllers\Api\V1\AssetWorkspaceController::class, 'docs'])
        ->name('assets.docs')->defaults('asset_permissions', ['asset.view'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.read', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::get('assets/settings', [\App\Modules\Asset\Controllers\Api\V1\AssetWorkspaceController::class, 'settings'])
        ->name('assets.settings')->defaults('asset_permissions', ['asset.manage_settings'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.settings.read', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::put('assets/settings', [\App\Modules\Asset\Controllers\Api\V1\AssetWorkspaceController::class, 'updateSettings'])
        ->name('assets.settings.update')->defaults('asset_permissions', ['asset.manage_settings'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.settings.update', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::get('assets/alerts/summary', [\App\Modules\Asset\Controllers\Api\V1\AssetWorkspaceController::class, 'summary'])
        ->name('assets.alerts.summary')->defaults('asset_permissions', ['asset.view'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.read', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::get('assets/alerts', [\App\Modules\Asset\Controllers\Api\V1\AssetWorkspaceController::class, 'alerts'])
        ->name('assets.alerts')->defaults('asset_permissions', ['asset.view'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.read', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::post('assets/alerts/sync/preview', [\App\Modules\Asset\Controllers\Api\V1\AssetProviderController::class, 'previewAlerts'])
        ->name('assets.alerts.preview')->defaults('asset_permissions', ['asset.view', 'asset.update'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.sync', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::post('assets/alerts/sync', [\App\Modules\Asset\Controllers\Api\V1\AssetProviderController::class, 'syncAlerts'])
        ->name('assets.alerts.sync')->defaults('asset_permissions', ['asset.view', 'asset.update'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.sync', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::post('assets/rmm/preview', [\App\Modules\Asset\Controllers\Api\V1\AssetProviderController::class, 'previewRmm'])
        ->name('assets.rmm.preview')->defaults('asset_permissions', ['asset.view', 'asset.update', 'integration.rmm_manage'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.sync', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::post('assets/rmm/sync', [\App\Modules\Asset\Controllers\Api\V1\AssetProviderController::class, 'syncRmm'])
        ->name('assets.rmm.sync')->defaults('asset_permissions', ['asset.view', 'asset.update', 'integration.rmm_manage'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.sync', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::get('assets/operations/{operation}', [\App\Modules\Asset\Controllers\Api\V1\AssetWorkspaceController::class, 'operation'])
        ->name('assets.operations.show')->defaults('asset_permissions', [])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.read', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::get('assets', [\App\Modules\Asset\Controllers\Api\V1\AssetController::class, 'index'])
        ->name('assets.index')->defaults('asset_permissions', ['asset.view'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.read', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::post('assets', [\App\Modules\Asset\Controllers\Api\V1\AssetController::class, 'store'])
        ->name('assets.store')->defaults('asset_permissions', ['asset.create'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.create', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::get('assets/{asset}', [\App\Modules\Asset\Controllers\Api\V1\AssetController::class, 'show'])
        ->name('assets.show')->defaults('asset_permissions', ['asset.view'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.read', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::put('assets/{asset}', [\App\Modules\Asset\Controllers\Api\V1\AssetController::class, 'update'])
        ->name('assets.update')->defaults('asset_permissions', ['asset.update'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.update', \App\Modules\Asset\Support\AssetApiAccess::class]);

    Route::patch('assets/{asset}', [\App\Modules\Asset\Controllers\Api\V1\AssetController::class, 'update'])
        ->name('assets.update.patch')->defaults('asset_permissions', ['asset.update'])
        ->middleware([\App\Modules\Asset\Support\AssetApiErrors::class, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':assets.update', \App\Modules\Asset\Support\AssetApiAccess::class]);

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
