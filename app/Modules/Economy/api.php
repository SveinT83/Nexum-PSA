<?php

use App\Modules\Economy\Controllers\Api\V1\EconomyController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
|--------------------------------------------------------------------------
| Economy API routes
|--------------------------------------------------------------------------
|
| These routes expose the same order preparation workflow used by the tech UI.
| External agents may generate billing orders, inspect draft order data, and
| move orders through the draft/ready states without bypassing domain guards.
|
*/

Route::prefix('economy')
    ->name('economy.')
    ->group(function (): void {
        Route::get('orders', [EconomyController::class, 'index'])
            ->middleware(CheckAbilities::class.':economy.read')
            ->name('orders.index');

        Route::post('orders/generate', [EconomyController::class, 'generate'])
            ->middleware(CheckAbilities::class.':economy.create')
            ->name('orders.generate');

        Route::get('orders/{order}', [EconomyController::class, 'show'])
            ->middleware(CheckAbilities::class.':economy.read')
            ->name('orders.show');

        Route::post('orders/{order}/ready', [EconomyController::class, 'markReady'])
            ->middleware(CheckAbilities::class.':economy.update')
            ->name('orders.ready');

        Route::post('orders/{order}/draft', [EconomyController::class, 'markDraft'])
            ->middleware(CheckAbilities::class.':economy.update')
            ->name('orders.draft');

        Route::delete('orders/{order}', [EconomyController::class, 'destroyOrder'])
            ->middleware(CheckAbilities::class.':economy.delete')
            ->name('orders.destroy');

        Route::delete('orders/{order}/lines/{line}', [EconomyController::class, 'destroyLine'])
            ->middleware(CheckAbilities::class.':economy.delete')
            ->name('orders.lines.destroy');
    });
