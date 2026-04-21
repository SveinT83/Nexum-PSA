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
