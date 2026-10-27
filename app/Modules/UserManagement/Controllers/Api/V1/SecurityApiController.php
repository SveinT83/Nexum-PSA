<?php

namespace App\Modules\UserManagement\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Actions\ConfirmUserTwoFactor;
use App\Modules\UserManagement\Actions\UserTwoFactorPolicy;
use App\Modules\UserManagement\Sso\SsoAccess;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/** Session-bound local security operations never produce general durable receipts. */
class SecurityApiController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user()->fresh();

        return response()->json(['data' => ['two_factor_enabled' => $user->two_factor_secret !== null,
            'two_factor_confirmed' => $user->two_factor_confirmed_at !== null,
            'material_url' => route('api.v1.users.security.material'), 'session_required' => true]])->header('Cache-Control', 'no-store');
    }

    public function material(Request $request)
    {
        $user = app(SsoAccess::class)->reauthenticate($request);

        return response()->json(['data' => ['setup_key' => $user->two_factor_secret && ! $user->two_factor_confirmed_at
            ? \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
            'recovery_codes' => $user->two_factor_recovery_codes ? $user->recoveryCodes() : [],
            'issuer' => config('app.name', 'Nexum PSA'), 'account_name' => $user->email]])->header('Cache-Control', 'no-store');
    }

    public function enable(Request $request, EnableTwoFactorAuthentication $action)
    {
        return $this->mutate($request, $action);
    }

    private function mutate(Request $request, callable $action)
    {
        $password = $request->input('current_password');
        try {
            $user = app(SsoAccess::class)->reauthenticate($request);
            $request->merge(['current_password' => $password]);
            $action($user);

            return $this->show($request);
        } finally {
            $request->request->remove('current_password');
            if (is_string($password)) {
                sodium_memzero($password);
            }
        }
    }

    public function confirm(Request $request, ConfirmUserTwoFactor $action)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $user = app(SsoAccess::class)->reauthenticate($request);
        $action->handle($user, $data['code']);

        return $this->show($request);
    }

    public function disable(Request $request, DisableTwoFactorAuthentication $action)
    {
        return $this->mutate($request, $action);
    }

    public function recoveryCodes(Request $request, GenerateNewRecoveryCodes $action)
    {
        $user = app(SsoAccess::class)->reauthenticate($request);
        abort_unless($user->two_factor_secret, 422, 'Enable two-factor authentication first.');
        $action($user);

        return response()->json(['data' => ['recovery_codes' => $user->fresh()->recoveryCodes()]])->header('Cache-Control', 'no-store');
    }

    public function password(Request $request, \App\Actions\Fortify\UpdateUserPassword $action)
    {
        $action->update($request->user(), $request->only(['current_password', 'password', 'password_confirmation']));

        return response()->json(['data' => ['password_updated' => true]])->header('Cache-Control', 'no-store');
    }

    public function policy(UserTwoFactorPolicy $policy)
    {
        return response()->json(['data' => $policy->read()]);
    }

    public function updatePolicy(Request $request, UserTwoFactorPolicy $policy)
    {
        return response()->json(['data' => $policy->update($request)]);
    }
}
