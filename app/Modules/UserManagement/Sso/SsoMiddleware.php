<?php

namespace App\Modules\UserManagement\Sso;

use App\Models\Core\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Enforce grant revocation and prevent secret-bearing callback/2FA exception output. */
class SsoMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $sensitive = $request->routeIs('sso.*', 'tech.profile.sso*', 'tech.admin.user_management.sso*');
        $pending = $request->session()->get('sso.pending');
        $grant = $request->session()->get('sso.grant');
        if (($sensitive || $pending) && class_exists(\Laravel\Telescope\Telescope::class)) {
            \Laravel\Telescope\Telescope::stopRecording();
        }
        try {
            $sessions = app(SsoSessions::class);
            if (is_string($pending)) {
                if ($request->user() !== null) {
                    $sessions->clear($request);
                    throw new SsoFailure;
                }
                if ($request->isMethod('POST') && $request->routeIs('login.store')) {
                    DB::table('user_sso_sessions')->where('id', $pending)->update(['revoked_at' => now()]);
                    $request->session()->forget(['sso.pending', 'sso.pending_until', 'login']);
                } else {
                    $user = User::find($request->session()->get('login.id'));
                    if ((int) $request->session()->get('sso.pending_until', 0) <= time()
                        || ! $sessions->valid($pending, $user)) {
                        $sessions->clear($request);
                        throw new SsoFailure;
                    }
                    $request->attributes->set('sso.verified_pending', [$pending, $user->id, (int) $user->auth_security_epoch]);
                }
            }
            if (is_string($grant) && ! $sessions->valid($grant, $request->user()?->fresh())) {
                $sessions->clear($request);

                return redirect()->route('login')->withErrors(['sso' => 'Your work account session has ended. Please sign in again.']);
            }
            $response = $next($request);
        } catch (Throwable $error) {
            if (! $sensitive && ! $pending) {
                throw $error;
            }
            // No upstream exception text or request contents reach logs/flash/debug pages.
            Log::warning('SSO request denied.', ['exception_class' => get_class($error)]);
            if (($request->routeIs('sso.callback', 'two-factor.login.store')) && ($request->session()->has('sso.pending') || $request->session()->has('sso.grant'))) {
                app(SsoSessions::class)->clear($request);
            }
            $response = $request->routeIs('sso.backchannel')
                ? response()->json(['error' => 'invalid_logout_token'], 400)
                : redirect()->route('login')->withErrors(['sso' => (new SsoFailure)->getMessage()]);
        }
        if ($sensitive || $pending || $grant) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        return $response;
    }
}
