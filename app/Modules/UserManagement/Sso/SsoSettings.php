<?php

namespace App\Modules\UserManagement\Sso;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Configuration replacement invalidates pending flows and every old provider grant. */
class SsoSettings
{
    public function save(Request $request): void
    {
        $actor = app(SsoAccess::class)->reauthenticate($request);
        $proof = app(SsoAccess::class)->fingerprint($actor);
        $data = $request->validate([
            'issuer' => ['required', 'string', 'max:500'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:4096'],
            'enabled' => ['nullable', 'boolean'],
        ]);
        $request->request->remove('client_secret');
        OidcClient::assertIssuer($data['issuer']);
        $existing = SsoProvider::find(1);
        if ($existing && ($existing->issuer !== $data['issuer'] || $existing->client_id !== $data['client_id'])
            && empty($data['client_secret'])) {
            throw new SsoFailure; // Never forward an old credential to a changed client or issuer.
        }

        $candidate = new SsoProvider([
            'issuer' => $data['issuer'], 'client_id' => $data['client_id'],
            'client_secret' => $data['client_secret'] ?? $existing?->client_secret,
        ]);
        if (! $candidate->client_secret) {
            throw new SsoFailure;
        }
        if ($existing && $existing->issuer !== $candidate->issuer
            && DB::table('user_external_identities')->exists()) {
            throw new SsoFailure; // Unlink explicitly before changing the identity namespace.
        }
        new OidcClient($candidate); // Verify trusted discovery; not a completed client login.
        DB::transaction(function () use ($candidate, $existing, $data, $request, $actor, $proof) {
            $current = SsoProvider::query()->lockForUpdate()->find(1);
            $freshActor = $actor->newQuery()->whereKey($actor->id)->lockForUpdate()->first();
            if (! app(SsoAccess::class)->allowed($freshActor) || ! $freshActor->can('user.manage_2fa')
                || ! hash_equals($proof, app(SsoAccess::class)->fingerprint($freshActor))) {
                throw new SsoFailure;
            }

            if ($current?->revision !== $existing?->revision) {
                throw new SsoFailure;
            }
            $candidate->id = 1;
            $candidate->revision = ($current?->revision ?? 0) + 1;
            $candidate->enabled = (bool) ($data['enabled'] ?? false);
            $candidate->verified_at = now();
            $candidate->exists = $current !== null;
            $candidate->save();
            DB::table('user_sso_sessions')->update(['revoked_at' => now()]);
            activity('sso')->causedBy($request->user())->log('provider_configuration_updated');
        });
    }
}
