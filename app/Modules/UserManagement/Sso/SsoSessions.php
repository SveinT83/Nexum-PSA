<?php

namespace App\Modules\UserManagement\Sso;

use App\Models\Core\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Driver-independent server-side session grants, checked on every web request. */
class SsoSessions
{
    public function __construct(private SsoAccess $access) {}

    public function create(User $user, SsoProvider $provider, object $claims): string
    {
        $id = (string) Str::uuid();
        DB::table('user_sso_sessions')->where('expires_at', '<', now())->delete();
        DB::table('user_sso_sessions')->insert([
            'id' => $id, 'user_id' => $user->id,
            'identity_key' => SsoAccess::identityKey($provider->issuer, $claims->sub),
            'sid_hash' => isset($claims->sid) ? hash('sha256', $provider->issuer."\0".$claims->sid) : null,
            'security_fingerprint' => $this->access->fingerprint($user),
            'provider_revision' => $provider->revision, 'created_at' => now(),
            'expires_at' => now()->addMinutes(min(60, max(1, (int) config('sso.session_minutes', 60)))),
        ]);

        return $id;
    }

    public function valid(string $id, ?User $user): bool
    {
        $provider = SsoProvider::available();
        $grant = DB::table('user_sso_sessions')->where('id', $id)->first();

        return $provider && $grant && $this->access->allowed($user)
            && (int) $grant->user_id === $user->id && $grant->revoked_at === null
            && now()->lt($grant->expires_at) && (int) $grant->provider_revision === $provider->revision
            && hash_equals($grant->security_fingerprint, $this->access->fingerprint($user))
            && DB::table('user_external_identities')->where('user_id', $user->id)
                ->where('identity_key', $grant->identity_key)->exists();
    }

    public function finishLogin(Login $event): void
    {
        $request = request();
        if ($event->guard !== 'web' || ! $request->hasSession()) {
            return;
        }
        $id = $request->session()->get('sso.pending');
        if (! is_string($id)) {
            return;
        }
        if (! $event->user instanceof User || ! $this->valid($id, $event->user->fresh())) {
            $this->clear($request);
            throw new SsoFailure;
        }
        activity('sso')->causedBy($event->user)->log('work_account_login');
        $request->session()->put('sso.grant', $id);
        $request->session()->forget(['sso.pending', 'sso.pending_until', 'login']);
    }

    public function clear(Request $request): void
    {
        $ids = array_filter([$request->session()->get('sso.grant'), $request->session()->get('sso.pending')], 'is_string');
        try {
            if ($ids !== []) {
                DB::table('user_sso_sessions')->whereIn('id', $ids)->update(['revoked_at' => now()]);
            }
        } catch (\Throwable) {
            // Database failure must not prevent clearing the local authenticated session.
            \Illuminate\Support\Facades\Log::warning('SSO grant revocation persistence unavailable.');
        }
        try {
            // No remember-token mutation: SSO never creates a remember-me cookie.
            Auth::guard('web')->logoutCurrentDevice();
        } catch (\Throwable) {
            Auth::guard('web')->forgetUser();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /** Carry only this pending login across the guarded recovery-code epoch increment. */
    public function recoveryConsumed(\App\Modules\UserManagement\Events\RecoveryCodesChanged $event): void
    {
        $request = request();
        $checked = $request->attributes->get('sso.verified_pending');
        if ($event->outcome !== \App\Modules\UserManagement\Security\Enums\UserSecurityFlowKind::RecoveryCodeConsume
            || ! $request->routeIs('two-factor.login.store')
            || ! app(\App\Modules\UserManagement\Security\Support\UserSecurityAuthCutover::class)->isEnforced()
            || ! is_array($checked) || count($checked) !== 3 || $checked[1] !== $event->userId
            || $request->session()->get('sso.pending') !== $checked[0]) {
            return;
        }
        // This event is emitted only after the existing protected writer finalizes its audit/gate.
        DB::transaction(function () use ($checked, $event) {
            $user = User::find($event->userId);
            $grant = DB::table('user_sso_sessions')->where('id', $checked[0])->lockForUpdate()->first();
            if (! $this->access->allowed($user) || ! $grant || $grant->revoked_at !== null
                || now()->gte($grant->expires_at) || (int) $grant->user_id !== $event->userId
                || (int) $user->auth_security_epoch !== $checked[2] + 1) {
                throw new SsoFailure;
            }
            $before = clone $user;
            $before->auth_security_epoch = $checked[2];
            if (! hash_equals($grant->security_fingerprint, $this->access->fingerprint($before))) {
                throw new SsoFailure;
            }
            DB::table('user_sso_sessions')->where('id', $checked[0])
                ->update(['security_fingerprint' => $this->access->fingerprint($user)]);
        });
    }
}
