<?php
use App\Modules\Clients\Controllers\Api\V1\ClientController;
use Illuminate\Support\Facades\Route;

Route::apiResource('clients', ClientController::class)->only(['index', 'show']);
Route::get('clients/{client}/assets', [ClientController::class, 'assets'])->name('clients.assets');
