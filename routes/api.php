<?php

use App\Http\Controllers\Api\V1\Clients\ClientController;
use App\Http\Controllers\Api\V1\Assets\AssetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('clients', ClientController::class)->only(['index', 'show']);
        Route::get('clients/{client}/assets', [ClientController::class, 'assets'])->name('clients.assets');
        Route::apiResource('assets', AssetController::class)->only(['index', 'show']);
    });
});

/*
|--------------------------------------------------------------------------
| War Room API Routes
|--------------------------------------------------------------------------
|
| These endpoints provide read-only access to N-Able RMM data for the
| War Room dashboard. They transform N-Able's XML API into JSON for
| consumption by the War Room collector.
|
| TODO: Add authentication middleware (token-based or IP whitelist)
|
*/

use App\Http\Controllers\Api\WarRoom\ClientsController as WarRoomClientsController;
use App\Http\Controllers\Api\WarRoom\AssetsController as WarRoomAssetsController;

Route::prefix('warroom')->name('api.warroom.')->group(function () {
    // Client endpoints
    Route::get('clients', [WarRoomClientsController::class, 'index'])
        ->name('clients.index');
    Route::get('clients/{id}', [WarRoomClientsController::class, 'show'])
        ->name('clients.show');
    Route::post('clients/{id}/sync', [WarRoomClientsController::class, 'sync'])
        ->name('clients.sync');

    // Asset endpoints
    Route::get('clients/{clientId}/assets', [WarRoomAssetsController::class, 'index'])
        ->name('clients.assets.index');
    Route::post('clients/{clientId}/assets/sync', [WarRoomAssetsController::class, 'sync'])
        ->name('clients.assets.sync');
    Route::get('assets/{assetId}', [WarRoomAssetsController::class, 'show'])
        ->name('assets.show');
});
