<?php

namespace App\Modules\UserManagement\Providers;

use App\Modules\UserManagement\Sso\SsoMiddleware;
use App\Modules\UserManagement\Sso\SsoSessions;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Add session enforcement without changing Fortify or Vault-owned security bindings. */
class SsoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', SsoMiddleware::class);
        // Laravel's routing pipeline renders exceptions before outer middleware catches them.
        // Register privacy here as well so no callback/token error can become a debug response.
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $sensitive = static fn ($request): bool => $request->routeIs('sso.*', 'tech.profile.sso*', 'tech.admin.user_management.sso*')
            || ($request->hasSession() && $request->session()->has('sso.pending'));
        $handler->reportable(function (\Throwable $error) use ($sensitive) {
            if ($sensitive(request())) {
                \Illuminate\Support\Facades\Log::warning('SSO request denied.', ['exception_class' => get_class($error)]);

                return false;
            }
        });
        $handler->renderable(function (\Throwable $error, \Illuminate\Http\Request $request) use ($sensitive) {
            if (! $sensitive($request)) {
                return null;
            }
            if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $error->getStatusCode() === 403) {
                return response('Access denied.', 403)->header('Cache-Control', 'no-store');
            }
            if (($request->routeIs('sso.callback', 'two-factor.login.store')) && ($request->session()->has('sso.pending') || $request->session()->has('sso.grant'))) {
                app(SsoSessions::class)->clear($request);
            }

            return $request->routeIs('sso.backchannel')
                ? response()->json(['error' => 'invalid_logout_token'], 400)->header('Cache-Control', 'no-store')
                : redirect()->route('login')->withErrors(['sso' => (new \App\Modules\UserManagement\Sso\SsoFailure)->getMessage()]);
        });

        Event::listen(Login::class, fn (Login $event) => app(SsoSessions::class)->finishLogin($event));
        Event::listen(\App\Modules\UserManagement\Events\RecoveryCodesChanged::class,
            fn ($event) => app(SsoSessions::class)->recoveryConsumed($event));

        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->guard !== 'web' || ! request()->hasSession()) {
                return;
            }
            $grant = request()->session()->pull('sso.grant');
            if (is_string($grant)) {
                DB::table('user_sso_sessions')->where('id', $grant)->update(['revoked_at' => now()]);
            }
        });
    }
}
