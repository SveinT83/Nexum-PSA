<?php

namespace App\Modules\UserManagement\Sso;

use App\Models\Core\User;
use App\Modules\UserManagement\Security\Services\DatabaseTwoFactorChallenge;
use App\Modules\UserManagement\Security\Support\UserSecurityAuthCutover;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;

/** Owns one-use login/link correlation; no account provisioning or upstream role writes. */
class SsoFlow
{
    public function __construct(private SsoAccess $access, private SsoSessions $sessions) {}

    public function start(Request $request, string $mode): string
    {
        app(UserSecurityAuthCutover::class)->isEnforced();
        $provider = SsoProvider::available();
        if (! $provider || ! $request->isSecure()) {
            throw new SsoFailure;
        }
        $user = $mode === 'link' ? $this->access->reauthenticate($request) : null;
        if ($mode === 'login' && Auth::check()) {
            throw new SsoFailure;
        }
        $client = new OidcClient($provider);
        $attempt = [
            'state' => Str::random(64), 'nonce' => Str::random(64), 'verifier' => Str::random(96),
            'mode' => $mode, 'revision' => $provider->revision, 'user_id' => $user?->id,
            'fingerprint' => $user ? $this->access->fingerprint($user) : null,
        ];
        $binding = Str::random(64);
        $request->session()->put('sso.binding', $binding);
        DB::table('user_sso_attempts')->where('expires_at', '<', now())->delete();
        DB::table('user_sso_attempts')->insert([
            'state_hash' => hash('sha256', $attempt['state']), 'binding_hash' => hash('sha256', $binding),
            'payload' => Crypt::encryptString(json_encode($attempt, JSON_THROW_ON_ERROR)),
            'expires_at' => now()->addSeconds(300),
        ]);

        return $client->authorizationUrl($attempt);
    }

    public function callback(Request $request): \Illuminate\Http\RedirectResponse
    {
        if (! $request->isSecure()) {
            throw new SsoFailure;
        }
        $state = $request->query('state');
        $code = $request->query('code');
        $binding = $request->session()->pull('sso.binding');
        if (! is_string($state) || strlen($state) !== 64 || ! is_string($binding)) {
            throw new SsoFailure;
        }
        $attempt = DB::transaction(function () use ($state, $binding) {
            $key = hash('sha256', $state);
            $row = DB::table('user_sso_attempts')->where('state_hash', $key)->lockForUpdate()->first();
            if (! $row || ! hash_equals($row->binding_hash, hash('sha256', $binding)) || now()->gte($row->expires_at)) {
                throw new SsoFailure;
            }
            DB::table('user_sso_attempts')->where('state_hash', $key)->delete();

            return json_decode(Crypt::decryptString($row->payload), true, 32, JSON_THROW_ON_ERROR);
        });
        if ($request->has('error') || ! is_string($code) || $code === '' || strlen($code) > 8192) {
            throw new SsoFailure;
        }
        app(UserSecurityAuthCutover::class)->isEnforced();
        $provider = SsoProvider::available();
        if (! $provider || $provider->revision !== $attempt['revision']) {
            throw new SsoFailure;
        }
        $claims = (new OidcClient($provider))->exchange($code, $attempt);

        $result = DB::transaction(function () use ($request, $claims, $attempt) {
            $current = SsoProvider::query()->lockForUpdate()->find(1);
            if (! $current || ! $current->enabled || $current->revision !== $attempt['revision']) {
                throw new SsoFailure;
            }
            $key = SsoAccess::identityKey($current->issuer, $claims->sub);
            if ($attempt['mode'] === 'link') {
                $user = $request->user()?->fresh();
                if (! $this->access->allowed($user) || $user->id !== $attempt['user_id']
                    || ! hash_equals($attempt['fingerprint'], $this->access->fingerprint($user))) {
                    throw new SsoFailure;
                }
                // Unique user and exact-byte identity keys prevent account replacement and races.
                DB::table('user_external_identities')->insert([
                    'user_id' => $user->id, 'issuer' => $current->issuer, 'subject' => $claims->sub,
                    'identity_key' => $key, 'created_at' => now(), 'updated_at' => now(),
                ]);
                activity('sso')->causedBy($user)->log('work_account_linked');

                return redirect()->route('tech.profile.sso')->with('success', 'Work account linked.');
            }
            if (Auth::check()) {
                throw new SsoFailure;
            }
            $identity = DB::table('user_external_identities')->where('identity_key', $key)->first();
            $user = $identity ? User::find($identity->user_id) : null;
            if (! $this->access->allowed($user)) {
                throw new SsoFailure;
            }

            return [$user, $this->sessions->create($user, $current, $claims)];
        });

        if (! is_array($result)) {
            return $result; // Explicit linking has completed; it is not a new login.
        }
        [$user, $grant] = $result;
        $request->session()->forget(['login', 'auth.password_confirmed_at']);
        $request->session()->put(['sso.pending' => $grant, 'sso.pending_until' => time() + 300]);
        // UserSecurity owns its own non-nestable fence transaction. Finish our provider/link
        // transaction first, then revalidate the grant against current state at login.
        if ($user->hasConfirmedTwoFactor()) {
            if (app(UserSecurityAuthCutover::class)->isEnforced()) {
                app(DatabaseTwoFactorChallenge::class)->capture($request, $user);
            }
            $request->session()->put(['login.id' => $user->id, 'login.remember' => false]);
            event(new TwoFactorAuthenticationChallenged($user));

            return redirect()->route('two-factor.login');
        }
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();

        return redirect()->route('tech.dashboard');
    }

    public function unlink(Request $request): void
    {
        $user = $this->access->reauthenticate($request);
        DB::transaction(function () use ($user) {
            // Share the provider lock with callbacks and administrative changes.
            SsoProvider::query()->lockForUpdate()->find(1);
            DB::table('user_external_identities')->where('user_id', $user->id)->delete();
            DB::table('user_sso_sessions')->where('user_id', $user->id)->update(['revoked_at' => now()]);
            activity('sso')->causedBy($user)->log('work_account_unlinked');
        });
    }

    public function backchannel(string $token): void
    {
        app(UserSecurityAuthCutover::class)->isEnforced();
        $provider = SsoProvider::available();
        if (! $provider) {
            throw new SsoFailure;
        }
        $claims = (new OidcClient($provider))->logoutClaims($token);
        DB::transaction(function () use ($provider, $claims) {
            $current = SsoProvider::query()->lockForUpdate()->find(1);
            if (! $current || $current->revision !== $provider->revision) {
                throw new SsoFailure;
            }
            DB::table('user_sso_logout_receipts')->where('expires_at', '<', now())->delete();
            DB::table('user_sso_logout_receipts')->insert([
                'token_key' => hash('sha256', $provider->issuer."\0".$claims->jti),
                'expires_at' => now()->addMinutes(10),
            ]);
            $query = DB::table('user_sso_sessions')->where('provider_revision', $provider->revision);
            if (isset($claims->sub)) {
                $query->where('identity_key', SsoAccess::identityKey($provider->issuer, $claims->sub));
            }
            if (isset($claims->sid)) {
                $query->where('sid_hash', hash('sha256', $provider->issuer."\0".$claims->sid));
            }
            $query->update(['revoked_at' => now()]);
        });
    }
}
