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
        channels: __DIR__.'/../routes/channels.php',
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
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Alias custom middleware
        $middleware->alias([
            'tech' => \App\Http\Middleware\TechAccess::class,
            'admin' => \App\Http\Middleware\AdminAccess::class,
            'tech.permission' => \App\Http\Middleware\EnforceTechRoutePermission::class,
            '2fa.required' => \App\Http\Middleware\RequireTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Provider material must never be copied to the session's validation
        // old-input bag. Include common aliases so future forms fail closed.
        $exceptions->dontFlash([
            'imap_host',
            'imap_port',
            'imap_encryption',
            'imap_transport',
            'imap_auth_type',
            'imap_username',
            'imap_secret',
            'imap_password',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_transport',
            'smtp_auth_type',
            'smtp_username',
            'smtp_secret',
            'smtp_password',
            'provider_username',
            'provider_secret',
            'provider_password',
            'credential',
            'credentials',
            'access_token',
            'refresh_token',
            'client_secret',
            'api_key',
            'private_endpoint_reason',
            'trusted_cidr_name',
            'trust_mode',
        ]);

        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }

            return $request->expectsJson();
        });
    })
    ->create();
