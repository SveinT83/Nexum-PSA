<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        then: function () {
            // Teknisk grensesnitt
            Route::middleware('web')
                ->prefix('tech')
                ->as('tech.')
                ->group(base_path('routes/tech.php'));

            // Kundeportal
            Route::middleware('web')
                ->prefix('client')
                ->as('client.')
                ->group(base_path('routes/client.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias custom middleware
        $middleware->alias([
            'tech' => \App\Http\Middleware\TechAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
