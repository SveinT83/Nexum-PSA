<?php

namespace App\Modules\UserManagement\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Actions\AcceptUserInvitation;
use App\Modules\UserManagement\Sso\SsoFlow;
use App\Modules\UserManagement\Sso\SsoProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Public invitation capabilities and existing signed/session-bound SSO protocol. */
class PublicIdentityApiController extends Controller
{
    public function invitation(string $token, AcceptUserInvitation $action)
    {
        $invite = $action->inspect($token);

        return response()->json(['data' => ['name' => $invite->user->name, 'email' => $invite->user->email,
            'expires_at' => $invite->expires_at->toIso8601String(), 'valid' => true]]);
    }

    public function accept(Request $request, string $token, AcceptUserInvitation $action)
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']]);
        $user = $action->handle($token, $data['password']);
        if ($user->isActive()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        return response()->json(['data' => ['status' => $user->status, 'accepted' => true,
            'activation_pending' => $user->isPending(), 'login_url' => route('login')]]);
    }

    public function discovery()
    {
        return response()->json(['data' => ['available' => SsoProvider::available() !== null,
            'login_url' => route('sso.login'), 'callback_url' => route('sso.callback'),
            'backchannel_url' => route('sso.backchannel'), 'session_required' => true,
            'csrf_url' => url('/sanctum/csrf-cookie')]]);
    }

    public function start(Request $request, SsoFlow $flow)
    {
        return response()->json(['data' => ['authorization_url' => $flow->start($request, 'login'),
            'callback_url' => SsoProvider::endpoint('sso.callback')]]);
    }

    public function callback(Request $request, SsoFlow $flow)
    {
        $result = $flow->callback($request);

        return response()->json(['data' => ['completed' => true, 'authenticated' => Auth::guard('web')->check(),
            'redirect_url' => $result->getTargetUrl()]]);
    }

    public function backchannel(Request $request, SsoFlow $flow)
    {
        $data = $request->validate(['logout_token' => ['required', 'string', 'max:32768']]);
        $flow->backchannel($data['logout_token']);

        return response()->json(['data' => ['accepted' => true]]);
    }
}
