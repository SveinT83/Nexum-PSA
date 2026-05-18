<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        then: function () {

            // Technisian & Admin routes
            Route::middleware('web')
                ->prefix('tech')
                ->as('tech.')
                ->group(function () {
                    require base_path('routes/tech.php');
                    require base_path('routes/techAdmin.php');
                });

            // Client portal
            /*
            Route::middleware('web')
                ->prefix('client')
                ->as('client.')
                ->group(base_path('routes/client.php'));
            */
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias custom middleware
        $middleware->alias([
            'tech' => \App\Http\Middleware\TechAccess::class,
            'admin' => \App\Http\Middleware\AdminAccess::class,
            '2fa.required' => \App\Http\Middleware\RequireTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }

            return $request->expectsJson();
        });
    })
    ->create();


