<?php

use App\Modules\DataExchange\Controllers\Api\V1\DataExchangeController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Route::prefix('data-exchange')
    ->name('data-exchange.')
    ->group(function (): void {
        Route::get('profiles', [DataExchangeController::class, 'profiles'])
            ->middleware(CheckAbilities::class.':data_exchange.read')
            ->name('profiles.index');

        Route::post('profiles/{profile}/runs', [DataExchangeController::class, 'trigger'])
            ->middleware(CheckAbilities::class.':data_exchange.run')
            ->name('profiles.runs.store');

        Route::get('runs/{run}', [DataExchangeController::class, 'run'])
            ->middleware(CheckAbilities::class.':data_exchange.read')
            ->name('runs.show');

        Route::get('files/{file}/download', [DataExchangeController::class, 'download'])
            ->middleware(CheckAbilities::class.':data_exchange.download')
            ->name('files.download');

        Route::post('imports/dry-run', [DataExchangeController::class, 'dryRun'])
            ->middleware(CheckAbilities::class.':data_exchange.import')
            ->name('imports.dry-run');

        Route::post('imports/{preview}/commit', [DataExchangeController::class, 'commit'])
            ->middleware(CheckAbilities::class.':data_exchange.approve_import')
            ->name('imports.commit');
    });
