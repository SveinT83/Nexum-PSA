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
        $middleware->append(\App\Modules\Workday\Middleware\PreventWorkdayCopies::class);

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
            'code',
            'recovery_code',
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

        // Work text and absence input must not escape into sessions, debug responses or exception logs.
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, \Illuminate\Http\Request $request) {
            return \App\Modules\Workday\Support\WorkdayPrivacy::validation($e, $request);
        });
        $exceptions->report(function (\Throwable $e) {
            if (\App\Modules\Workday\Support\WorkdayPrivacy::matches(request())) {
                \Illuminate\Support\Facades\Log::error('Workday request failed.', ['exception_class' => get_class($e)]);

                return false;
            }
        });
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (\App\Modules\Workday\Support\WorkdayPrivacy::matches($request)
                && ! $e instanceof \Illuminate\Validation\ValidationException
                && ! $e instanceof \Illuminate\Auth\AuthenticationException
                && ! $e instanceof \Illuminate\Auth\Access\AuthorizationException
                && ! $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return $request->is('api/*') || $request->expectsJson()
                    ? response()->json(['message' => 'Workday request failed. Please retry or contact an administrator.'], 500)
                    : response('Workday request failed. Please retry or contact an administrator.', 500);
            }
        });

        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }

            return $request->expectsJson();
        });
    })
    ->create();
