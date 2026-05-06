<?php
use App\Modules\Clients\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\Assets\AssetController;
use Illuminate\Support\Facades\Route;

Route::apiResource('clients', ClientController::class)->only(['index', 'show']);
Route::get('clients/{client}/assets', [ClientController::class, 'assets'])->name('clients.assets');
Route::apiResource('assets', AssetController::class)->only(['index', 'show']);
