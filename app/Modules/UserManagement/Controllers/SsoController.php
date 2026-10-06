<?php

namespace App\Modules\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Sso\OidcClient;
use App\Modules\UserManagement\Sso\SsoFlow;
use App\Modules\UserManagement\Sso\SsoProvider;
use App\Modules\UserManagement\Sso\SsoSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Thin endpoints; the SSO services own authentication and identity persistence. */
class SsoController extends Controller
{
    public function start(Request $request, SsoFlow $flow)
    {
        return redirect()->away($flow->start($request, 'login'));
    }

    public function callback(Request $request, SsoFlow $flow)
    {
        return $flow->callback($request);
    }

    public function profile(Request $request)
    {
        return view('usermanagement::profile.sso', [
            'identity' => DB::table('user_external_identities')->where('user_id', $request->user()->id)->first(),
            'available' => SsoProvider::available() !== null,
        ]);
    }

    public function link(Request $request, SsoFlow $flow)
    {
        return redirect()->away($flow->start($request, 'link'));
    }

    public function unlink(Request $request, SsoFlow $flow)
    {
        $flow->unlink($request);

        return redirect()->route('tech.profile.sso')->with('success', 'Work account unlinked. SSO sessions have been revoked.');
    }

    public function logout(Request $request, SsoSessions $sessions)
    {
        $provider = SsoProvider::available();
        $url = $provider ? (new OidcClient($provider))->logoutUrl() : route('login');
        $sessions->clear($request);

        return redirect()->away($url);
    }

    public function backchannel(Request $request, SsoFlow $flow)
    {
        $token = $request->input('logout_token');
        abort_unless(is_string($token) && strlen($token) <= 32768, 400);
        $flow->backchannel($token);

        return response('', 200)->header('Cache-Control', 'no-store');
    }
}
