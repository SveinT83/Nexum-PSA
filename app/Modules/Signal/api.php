<?php

use App\Modules\Signal\Controllers\Api\V1\SignalController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::post('signals', [SignalController::class, 'store'])
    ->name('signals.store')
    ->middleware(CheckAbilities::class.':signals.create');
