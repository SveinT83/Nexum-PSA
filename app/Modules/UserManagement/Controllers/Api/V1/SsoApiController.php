<?php

namespace App\Modules\UserManagement\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Sso\OidcClient;
use App\Modules\UserManagement\Sso\SsoFlow;
use App\Modules\UserManagement\Sso\SsoProvider;
use App\Modules\UserManagement\Sso\SsoSessions;
use App\Modules\UserManagement\Sso\SsoSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Existing session-bound OIDC protocol; a bearer scope is never a linking proof. */
class SsoApiController extends Controller
{
    public function show(Request $request)
    {
        $identity = DB::table('user_external_identities')->where('user_id', $request->user()->id)->first();

        return response()->json(['data' => ['available' => SsoProvider::available() !== null,
            'linked' => $identity !== null, 'identity' => $identity ? ['issuer' => $identity->issuer, 'subject' => $identity->subject] : null,
            'session_required' => true]]);
    }

    public function link(Request $request, SsoFlow $flow)
    {
        return response()->json(['data' => ['authorization_url' => $flow->start($request, 'link'),
            'callback_url' => SsoProvider::endpoint('sso.callback')]]);
    }

    public function unlink(Request $request, SsoFlow $flow)
    {
        $flow->unlink($request);

        return $this->show($request);
    }

    public function logout(Request $request, SsoSessions $sessions)
    {
        $provider = SsoProvider::available();
        $url = $provider ? (new OidcClient($provider))->logoutUrl() : route('login');
        $sessions->clear($request);

        return response()->json(['data' => ['logged_out' => true, 'logout_url' => $url]]);
    }

    public function settings(?Request $request = null)
    {
        $request ??= request();
        // The existing SSO settings controller requires this exact native permission.
        abort_unless($request->user()->can('user.manage_2fa'), 403);
        $provider = SsoProvider::find(1);

        return response()->json(['data' => ['issuer' => $provider?->issuer, 'client_id' => $provider?->client_id,
            'client_secret_configured' => (bool) $provider?->getRawOriginal('client_secret'),
            'enabled' => (bool) $provider?->enabled, 'revision' => $provider?->revision ?? 0,
            'verified_at' => $provider?->verified_at?->toIso8601String(), 'runtime_enabled' => (bool) config('sso.enabled', false)]]);
    }

    public function updateSettings(Request $request, SsoSettings $settings)
    {
        $settings->save($request);

        return $this->settings($request);
    }
}
