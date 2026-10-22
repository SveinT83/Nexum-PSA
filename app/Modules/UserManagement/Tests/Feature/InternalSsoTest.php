<?php

namespace App\Modules\UserManagement\Tests\Feature;

use App\Models\Core\User;
use App\Modules\UserManagement\Sso\SsoAccess;
use App\Modules\UserManagement\Sso\SsoProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InternalSsoTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://identity.example.test/realms/staff';

    private static $privateKey;

    private array $claims = [];

    private string $token = '';

    private SsoProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sso.enabled' => true, 'app.url' => 'https://nexum.example.test']);
        URL::forceRootUrl('https://nexum.example.test');
        URL::forceScheme('https');
        Role::firstOrCreate(['name' => 'Tech', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'user.manage_2fa', 'guard_name' => 'web']);
        $this->provider = SsoProvider::create([
            'id' => 1, 'issuer' => self::ISSUER, 'client_id' => 'nexum',
            'client_secret' => 'synthetic-client-secret', 'enabled' => true,
            'revision' => 1, 'verified_at' => now(),
        ]);
        self::$privateKey ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $key = openssl_pkey_get_details(self::$privateKey)['rsa'];
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($key) {
            if ($request->url() === self::ISSUER.'/.well-known/openid-configuration') {
                return Http::response([
                    'issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/auth',
                    'token_endpoint' => self::ISSUER.'/token', 'jwks_uri' => self::ISSUER.'/certs',
                    'end_session_endpoint' => self::ISSUER.'/logout',
                    'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'],
                ]);
            }
            if ($request->url() === self::ISSUER.'/certs') {
                return Http::response(['keys' => [[
                    'kty' => 'RSA', 'kid' => 'test', 'use' => 'sig', 'alg' => 'RS256',
                    'n' => $this->b64($key['n']), 'e' => $this->b64($key['e']),
                ]]]);
            }
            if ($request->url() === self::ISSUER.'/token') {
                return Http::response(['id_token' => $this->token, 'access_token' => 'synthetic-access']);
            }

            return Http::response([], 404);
        });
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function sign(array $claims, array $header = []): string
    {
        $input = $this->b64(json_encode($header ?: ['alg' => 'RS256', 'kid' => 'test'])).'.'.$this->b64(json_encode($claims));
        openssl_sign($input, $signature, self::$privateKey, OPENSSL_ALGO_SHA256);

        return $input.'.'.$this->b64($signature);
    }

    private function employee(bool $linked = true): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE, 'password' => bcrypt('local-password')]);
        $user->assignRole('Tech');
        if ($linked) {
            DB::table('user_external_identities')->insert([
                'user_id' => $user->id, 'issuer' => self::ISSUER, 'subject' => 'employee-'.$user->id,
                'identity_key' => SsoAccess::identityKey(self::ISSUER, 'employee-'.$user->id),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function begin(User $user): array
    {
        $response = $this->get(route('sso.login'))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('S256', $parameters['code_challenge_method']);
        $this->assertSame('openid', $parameters['scope']);
        $this->claims = [
            'iss' => self::ISSUER, 'aud' => 'nexum', 'sub' => 'employee-'.$user->id,
            'sid' => 'keycloak-session-'.$user->id, 'nonce' => $parameters['nonce'],
            'iat' => time(), 'exp' => time() + 300,
        ];
        $this->token = $this->sign($this->claims);

        return $parameters;
    }

    private function finish(array $parameters)
    {
        return $this->get(route('sso.callback', ['state' => $parameters['state'], 'code' => 'synthetic-code']));
    }

    public function test_disabled_sso_hides_login_button_and_rejects_start(): void
    {
        config(['sso.enabled' => false]);
        $this->get('/login')->assertOk()->assertDontSee('Sign in with work account');
        $this->get(route('sso.login'))->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    public function test_linked_employee_logs_in_without_modifying_credentials_or_permissions(): void
    {
        $user = $this->employee();
        $before = $user->getAuthPassword();
        $parameters = $this->begin($user);
        $this->finish($parameters)->assertRedirect(route('tech.dashboard'))->assertSessionHas('sso.grant');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($before, $user->fresh()->getAuthPassword());
        $this->assertSame(['Tech'], $user->fresh()->getRoleNames()->all());
        $this->get(route('tech.profile.sso'))->assertOk()->assertSee('Linked provider');
        $this->assertDatabaseCount('user_sso_attempts', 0);
    }

    public function test_unknown_subject_never_links_by_email(): void
    {
        $user = $this->employee(false);
        $p = $this->begin($user);
        $this->claims['email'] = $user->email;
        $this->token = $this->sign($this->claims);
        $this->finish($p)->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        $this->assertGuest();
        $this->assertDatabaseCount('user_external_identities', 0);
    }

    public function test_portal_only_and_inactive_accounts_cannot_use_sso(): void
    {
        $user = $this->employee();
        $user->syncRoles([]);
        $p = $this->begin($user);
        $this->finish($p)->assertRedirect(route('login'));
        $this->assertGuest();
        $user->assignRole('Tech');
        $user->forceFill(['status' => User::STATUS_PENDING])->save();
        $p = $this->begin($user);
        $this->finish($p)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_expired_wrong_nonce_issuer_audience_and_signature_are_denied(): void
    {
        $user = $this->employee();
        foreach (['nonce' => 'wrong', 'iss' => 'https://wrong.test', 'aud' => 'other-app', 'exp' => 1] as $key => $value) {
            $p = $this->begin($user);
            $this->claims[$key] = $value;
            $this->token = $this->sign($this->claims);
            $this->finish($p)->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $p = $this->begin($user);
        $parts = explode('.', $this->token);
        $parts[2] = $this->b64(str_repeat('x', 256));
        $this->token = implode('.', $parts);
        $this->finish($p)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseCount('user_sso_sessions', 0);
    }

    public function test_replayed_callback_cannot_create_a_second_session(): void
    {
        $user = $this->employee();
        $p = $this->begin($user);
        $this->finish($p)->assertRedirect(route('tech.dashboard'));
        $this->finish($p)->assertRedirect(route('login'));
        $this->assertDatabaseCount('user_sso_sessions', 1);
    }

    private function requireTechTwoFactor(): void
    {
        DB::table('common_settings')->updateOrInsert(
            ['name' => 'enforce_two_factor'],
            ['type' => 'security', 'value' => '1', 'json' => null],
        );
        DB::table('common_settings')->updateOrInsert(
            ['name' => 'enforce_two_factor_roles'],
            ['type' => 'security', 'value' => null, 'json' => '["Tech"]'],
        );
    }

    public function test_sso_skips_local_challenge_without_removing_local_factors(): void
    {
        $this->requireTechTwoFactor();
        $user = $this->employee();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $fields = array_flip(['password', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes']);
        $before = hash('sha256', json_encode(array_intersect_key($user->fresh()->getAttributes(), $fields)));
        $p = $this->begin($user);
        $this->finish($p)->assertRedirect(route('tech.dashboard'))
            ->assertSessionHas('sso.grant')->assertSessionMissing('login.id')
            ->assertSessionMissing('sso.pending')->assertSessionMissing('auth.password_confirmed_at');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($before, hash('sha256', json_encode(array_intersect_key($user->fresh()->getAttributes(), $fields))));
        $this->get(route('tech.profile.sso'))->assertOk();

        // The same linked user still needs a local factor after choosing password login.
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => $user->email, 'password' => 'local-password'])
            ->assertRedirect(route('two-factor.login'))->assertSessionMissing('sso.grant');
        $this->assertGuest();
    }

    public function test_sso_satisfies_role_gate_without_local_enrollment(): void
    {
        $this->requireTechTwoFactor();
        $user = $this->employee();
        $p = $this->begin($user);
        $this->finish($p)->assertRedirect(route('tech.dashboard'));
        $this->get(route('tech.profile.sso'))->assertOk();
        $this->assertNull($user->fresh()->two_factor_secret);

        // No permanent exemption attaches to the user or external identity.
        $this->post('/logout')->assertRedirect();
        $this->post('/login', ['email' => $user->email, 'password' => 'local-password'])->assertRedirect();
        $this->get(route('tech.profile.sso'))->assertRedirect(route('tech.profile.security'));
    }

    public function test_link_or_request_flag_alone_cannot_satisfy_local_role_gate(): void
    {
        $this->requireTechTwoFactor();
        $user = $this->employee();
        $this->actingAs($user)->get(route('tech.profile.sso', ['sso' => ['grant' => 'fake'], 'mfa' => true]))
            ->assertRedirect(route('tech.profile.security'));
        $this->assertNull(session('sso.grant'));
    }

    public function test_invalid_grant_cannot_satisfy_role_gate(): void
    {
        $this->requireTechTwoFactor();
        $user = $this->employee();
        $this->actingAs($user)->withSession(['sso.grant' => 'nonexistent'])
            ->get(route('tech.profile.sso'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_other_users_grant_cannot_satisfy_role_gate(): void
    {
        $this->requireTechTwoFactor();
        $owner = $this->employee();
        $other = $this->employee();
        $grant = app(\App\Modules\UserManagement\Sso\SsoSessions::class)->create(
            $owner, $this->provider, (object) ['sub' => 'employee-'.$owner->id],
        );
        $this->actingAs($other)->withSession(['sso.grant' => $grant])
            ->get(route('tech.profile.sso'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_revoked_and_expired_grants_cannot_satisfy_role_gate(): void
    {
        $this->requireTechTwoFactor();
        $user = $this->employee();
        foreach (['revoked_at' => now(), 'expires_at' => now()->subMinute()] as $field => $value) {
            $p = $this->begin($user);
            $this->finish($p)->assertRedirect(route('tech.dashboard'));
            DB::table('user_sso_sessions')->where('id', session('sso.grant'))->update([$field => $value]);
            $this->get(route('tech.profile.sso'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_local_password_login_still_requires_totp_for_a_linked_user(): void
    {
        $user = $this->employee();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'local-password'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->assertNull(session('sso.pending'));
        $this->post(route('two-factor.login.store'), ['code' => 'wrong'])->assertRedirect();
        $this->assertGuest();
        $this->mock(TwoFactorAuthenticationProvider::class, function ($mock) {
            $mock->shouldReceive('verify')->withArgs(fn ($secret, $code) => $code === '123456')->andReturnTrue();
        });
        $this->post(route('two-factor.login.store'), ['code' => '123456'])->assertRedirect(route('tech.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('sso.grant'));
    }

    public function test_password_and_provider_changes_revoke_ss_o_sessions(): void
    {
        $user = $this->employee();
        $p = $this->begin($user);
        $this->finish($p);
        $user->forceFill(['password' => bcrypt('changed-password')])->save();
        $this->get(route('tech.profile.sso'))->assertRedirect(route('login'));
        $this->assertGuest();
        $p = $this->begin($user);
        $this->finish($p);
        $this->provider->increment('revision');
        $this->get(route('tech.profile.sso'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_expired_pending_factor_cannot_authenticate(): void
    {
        $user = $this->employee();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $grant = app(\App\Modules\UserManagement\Sso\SsoSessions::class)->create(
            $user, $this->provider, (object) ['sub' => 'employee-'.$user->id],
        );
        $this->withSession(['sso.pending' => $grant, 'sso.pending_until' => time() - 1, 'login.id' => $user->id])
            ->post(route('two-factor.login.store'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_link_password_failure_returns_safe_actionable_feedback(): void
    {
        $user = $this->employee(false);
        $before = $user->getAuthPassword();
        $this->actingAs($user)->post(route('tech.profile.sso.link'), [
            'current_password' => 'synthetic-wrong-password', 'code' => '987654',
        ])->assertRedirect(route('tech.profile.sso'))->assertSessionHasErrors('sso_reauthentication');
        $this->assertStringContainsString('Nexum password was not accepted', session('errors')->first('sso_reauthentication'));
        $this->assertFalse(session()->has('_old_input.current_password'));
        $this->assertFalse(session()->has('_old_input.code'));
        $this->get(route('tech.profile.sso'))->assertOk()
            ->assertSee('Nexum password was not accepted')
            ->assertDontSee('synthetic-wrong-password')->assertDontSee('987654');
        $this->assertSame($before, $user->fresh()->getAuthPassword());
        $this->assertDatabaseCount('user_sso_attempts', 0);
        $this->assertDatabaseCount('user_external_identities', 0);
        Http::assertNothingSent();
    }

    public function test_link_factor_failure_is_distinct_and_preserves_local_credentials(): void
    {
        $user = $this->employee(false);
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $before = $user->fresh()->getRawOriginal();
        $this->mock(TwoFactorAuthenticationProvider::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->andReturnFalse();
        });
        $this->actingAs($user)->post(route('tech.profile.sso.link'), [
            'current_password' => 'local-password', 'code' => '987654',
        ])->assertRedirect(route('tech.profile.sso'))->assertSessionHasErrors('sso_reauthentication');
        $this->assertStringContainsString('Nexum authenticator code was not accepted', session('errors')->first('sso_reauthentication'));
        $this->assertFalse(session()->has('_old_input.current_password'));
        $this->assertFalse(session()->has('_old_input.code'));
        $this->get(route('tech.profile.sso'))->assertOk()
            ->assertSee('Nexum authenticator code was not accepted')
            ->assertDontSee('local-password')->assertDontSee('987654');
        foreach (['password', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes'] as $field) {
            $this->assertSame($before[$field], $user->fresh()->getRawOriginal($field));
        }
        $this->assertDatabaseCount('user_sso_attempts', 0);
        $this->assertDatabaseCount('user_external_identities', 0);
        Http::assertNothingSent();
    }

    public function test_linking_requires_local_password_then_verified_subject_and_duplicate_is_rejected(): void
    {
        $user = $this->employee(false);
        $this->actingAs($user)->post(route('tech.profile.sso.link'), ['current_password' => 'wrong'])
            ->assertRedirect(route('tech.profile.sso'))->assertSessionHasErrors('sso_reauthentication');
        $this->assertDatabaseCount('user_external_identities', 0);
        $response = $this->actingAs($user)->post(route('tech.profile.sso.link'), ['current_password' => 'local-password'])->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $p);
        $this->token = $this->sign(['iss' => self::ISSUER, 'aud' => 'nexum', 'sub' => 'new-subject',
            'iat' => time(), 'exp' => time() + 300, 'nonce' => $p['nonce']]);
        $this->finish($p)->assertRedirect(route('tech.profile.sso'));
        $this->assertDatabaseHas('user_external_identities', ['user_id' => $user->id, 'subject' => 'new-subject']);
        $other = $this->employee(false);
        $response = $this->actingAs($other)->post(route('tech.profile.sso.link'), ['current_password' => 'local-password']);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $p);
        $this->token = $this->sign(['iss' => self::ISSUER, 'aud' => 'nexum', 'sub' => 'new-subject',
            'iat' => time(), 'exp' => time() + 300, 'nonce' => $p['nonce']]);
        $this->finish($p)->assertRedirect(route('login'));
        $this->assertDatabaseCount('user_external_identities', 1);
    }

    public function test_backchannel_logout_revokes_only_matching_session_and_rejects_replay(): void
    {
        $user = $this->employee();
        $p = $this->begin($user);
        $this->finish($p);
        $grant = session('sso.grant');
        $claims = ['iss' => self::ISSUER, 'aud' => 'nexum', 'sid' => 'different-session',
            'iat' => time(), 'jti' => 'first',
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => (object) []]];
        $this->post(route('sso.backchannel'), ['logout_token' => $this->sign($claims)])->assertOk();
        $this->assertNull(DB::table('user_sso_sessions')->where('id', $grant)->value('revoked_at'));
        $claims['sid'] = 'keycloak-session-'.$user->id;
        $claims['jti'] = 'second';
        $token = $this->sign($claims);
        $this->post(route('sso.backchannel'), ['logout_token' => $token])->assertOk();
        // A provider call has no browser session in reality.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post(route('sso.backchannel'), ['logout_token' => $token])->assertStatus(400);
        $this->assertNotNull(DB::table('user_sso_sessions')->where('id', $grant)->value('revoked_at'));
    }

    public function test_missing_logout_event_and_nonce_are_rejected(): void
    {
        $base = ['iss' => self::ISSUER, 'aud' => 'nexum', 'sub' => 'employee-1', 'iat' => time(), 'jti' => 'bad'];
        $this->post(route('sso.backchannel'), ['logout_token' => $this->sign($base)])->assertStatus(400);
        $base['events'] = ['http://schemas.openid.net/event/backchannel-logout' => (object) []];
        $base['nonce'] = 'forbidden';
        $this->post(route('sso.backchannel'), ['logout_token' => $this->sign($base)])->assertStatus(400);
    }

    public function test_settings_are_permission_guarded_and_secrets_stay_encrypted(): void
    {
        $user = $this->employee(false);
        $this->actingAs($user)->get(route('tech.admin.user_management.sso'))->assertForbidden();
        $user->givePermissionTo('user.manage_2fa');
        $this->actingAs($user)->get(route('tech.admin.user_management.sso'))->assertOk()
            ->assertDontSee('synthetic-client-secret');
        $this->post(route('tech.admin.user_management.sso.update'), [
            'issuer' => self::ISSUER, 'client_id' => 'nexum', 'client_secret' => '',
            'current_password' => 'local-password', 'enabled' => '1',
        ])->assertRedirect(route('tech.admin.user_management.sso'));
        $this->assertSame('synthetic-client-secret', $this->provider->fresh()->client_secret);
        $this->assertStringNotContainsString('synthetic-client-secret', DB::table('user_sso_providers')->value('client_secret'));
        $this->assertSame(2, $this->provider->fresh()->revision);
    }

    public function test_subject_keys_are_case_sensitive_and_no_tokens_are_persisted(): void
    {
        $this->assertNotSame(SsoAccess::identityKey(self::ISSUER, 'Employee'), SsoAccess::identityKey(self::ISSUER, 'employee'));
        $user = $this->employee();
        $p = $this->begin($user);
        $this->finish($p);
        $stored = json_encode(DB::table('user_sso_sessions')->get());
        $this->assertStringNotContainsString($this->token, $stored);
        $this->assertStringNotContainsString('synthetic-access', $stored);
    }

    public function test_wrong_browser_binding_and_expired_attempt_are_denied(): void
    {
        $user = $this->employee();
        $p = $this->begin($user);
        $this->withSession(['sso.binding' => 'wrong-browser']);
        $this->finish($p)->assertRedirect(route('login'));
        $this->assertGuest();
        $p = $this->begin($user);
        DB::table('user_sso_attempts')->update(['expires_at' => now()->subMinute()]);
        $this->finish($p)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_unlink_revokes_grants_and_preserves_local_account(): void
    {
        $user = $this->employee();
        $p = $this->begin($user);
        $this->finish($p);
        $this->post(route('tech.profile.sso.unlink'), ['current_password' => 'local-password'])
            ->assertRedirect(route('tech.profile.sso'));
        $this->assertDatabaseCount('user_external_identities', 0);
        $this->assertNotNull(DB::table('user_sso_sessions')->value('revoked_at'));
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
        $this->get(route('tech.profile.sso'))->assertRedirect(route('login'));
    }

    public function test_session_expiry_and_disabled_provider_end_sso_without_affecting_local_login(): void
    {
        $user = $this->employee();
        $p = $this->begin($user);
        $this->finish($p);
        DB::table('user_sso_sessions')->update(['expires_at' => now()->subMinute()]);
        $this->get(route('tech.profile.sso'))->assertRedirect(route('login'));
        $this->assertGuest();
        $p = $this->begin($user);
        $this->finish($p);
        $this->provider->update(['enabled' => false]);
        $this->get(route('tech.profile.sso'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => 'local-password'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('sso.grant'));
    }

    public function test_unlinked_emergency_admin_can_log_in_locally_during_provider_connection_failure(): void
    {
        // Simulate an unreachable provider without interrupting the real shared Keycloak service.
        $admin = $this->employee(false);
        $admin->syncRoles(['Admin']);
        $passwordBefore = $admin->getAuthPassword();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('synthetic-provider-unavailable');
        });

        $this->get(route('sso.login'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('sso');
        $this->assertGuest();
        $this->assertStringNotContainsString('synthetic-provider-unavailable', session('errors')->first('sso'));
        $this->assertDatabaseCount('user_sso_sessions', 0);

        $this->post('/login', ['email' => $admin->email, 'password' => 'local-password'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('sso.grant'));
        $this->get(route('tech.profile.sso'))->assertOk()->assertSee('Link work account');
        $this->assertDatabaseCount('user_external_identities', 0);
        $this->assertSame($passwordBefore, $admin->fresh()->getAuthPassword());
        $this->assertSame(['Admin'], $admin->fresh()->getRoleNames()->all());
        $this->assertTrue((bool) $this->provider->fresh()->enabled);
    }

    public function test_provider_redirects_and_cross_origin_metadata_are_rejected_without_leaking_errors(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/auth',
                'token_endpoint' => 'https://attacker.example.test/token',
                'jwks_uri' => self::ISSUER.'/certs', 'end_session_endpoint' => self::ISSUER.'/logout',
                'response_types_supported' => ['code'], 'code_challenge_methods_supported' => ['S256'],
            ]),
        ]);
        $this->get(route('sso.login'))->assertRedirect(route('login'))->assertSessionHasErrors('sso');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'attacker'));
    }

    public function test_missing_id_token_nonce_or_expiry_and_hmac_algorithms_are_denied(): void
    {
        $user = $this->employee();
        foreach (['nonce', 'exp'] as $field) {
            $p = $this->begin($user);
            unset($this->claims[$field]);
            $this->token = $this->sign($this->claims);
            $this->finish($p)->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $p = $this->begin($user);
        $this->token = $this->sign($this->claims, ['alg' => 'HS256', 'kid' => 'test']);
        $this->finish($p)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_recovery_code_completes_existing_local_factor_flow(): void
    {
        $user = $this->employee();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $recovery = $user->recoveryCodes()[0];
        $this->post('/login', ['email' => $user->email, 'password' => 'local-password'])
            ->assertRedirect(route('two-factor.login'));
        $this->post(route('two-factor.login.store'), ['recovery_code' => $recovery])
            ->assertRedirect(route('tech.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('sso.grant'));
        $this->assertNotContains($recovery, $user->fresh()->recoveryCodes());
    }

    public function test_unknown_authentication_cutover_configuration_fails_closed(): void
    {
        config(['auth.providers.user_management.driver' => 'unknown-provider']);
        $this->get(route('sso.login'))->assertRedirect(route('login'));
        $this->assertDatabaseCount('user_sso_attempts', 0);
    }

    public function test_login_listener_failure_cannot_leave_an_authenticated_session(): void
    {
        $user = $this->employee();
        $p = $this->begin($user);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function () {
            throw new \RuntimeException('synthetic-sensitive-listener-detail');
        });
        $this->finish($p)->assertRedirect(route('login'))->assertSessionHasErrors('sso')
            ->assertDontSee('synthetic-sensitive-listener-detail');
        $this->assertGuest();
        $this->assertNotNull(DB::table('user_sso_sessions')->value('revoked_at'));
        $this->get(route('tech.profile.sso'))->assertRedirect(route('login'));
    }

    public function test_canonical_callback_is_pinned_and_plain_http_login_is_denied(): void
    {
        URL::forceRootUrl(null);
        $response = $this->get('https://untrusted-host.example.test/sso/login')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $this->assertSame('https://nexum.example.test/sso/callback', $parameters['redirect_uri']);
        $this->get('http://nexum.example.test/sso/login')->assertRedirect();
        $this->assertGuest();
        $this->assertDatabaseCount('user_sso_attempts', 1);
    }

    public function test_enforced_user_security_sso_preserves_factors_and_revokes_on_epoch_change(): void
    {
        // The protected provider is a separate optional rollout; keep this check on Dev
        // without importing unpublished Vault/UserSecurity into a legacy Main release.
        if (! class_exists(\App\Modules\UserManagement\Security\Auth\NexumUserSecurityProvider::class)) {
            $this->markTestSkipped('Protected UserSecurity provider is not installed in this release.');
        }

        $user = $this->employee();
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        if (! \Illuminate\Support\Facades\Schema::hasColumn('user_management', 'auth_security_epoch')) {
            \Illuminate\Support\Facades\Schema::table('user_management', function ($table) {
                $table->unsignedBigInteger('auth_security_epoch')->default(1);
                $table->string('two_factor_generation_id')->nullable();
            });
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('user_two_factor_generations')) {
            \Illuminate\Support\Facades\Schema::create('user_two_factor_generations', function ($table) {
                $table->string('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->string('state');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('terminal_at')->nullable();
                $table->string('terminal_reason')->nullable();
            });
        }
        $generation = (string) \Illuminate\Support\Str::uuid7();
        DB::table('user_two_factor_generations')->insert([
            'id' => $generation, 'user_id' => $user->id, 'state' => 'confirmed',
            'source' => 'migration_backfill', 'created_at' => now()->format('Y-m-d H:i:s.u'), 'confirmed_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
        DB::table('user_management')->where('id', $user->id)->update([
            'auth_security_epoch' => 1, 'two_factor_generation_id' => $generation,
        ]);
        // Release only this isolated SQLite fixture transaction so the real fence owns its transaction.
        DB::connection()->commit();
        config(['auth.providers.user_management.driver' => 'nexum-user-security']);
        $this->app['auth']->forgetGuards();
        $p = $this->begin($user);
        $this->finish($p)->assertRedirect(route('tech.dashboard'))
            ->assertSessionMissing('login.auth_security_epoch')
            ->assertSessionMissing('login.two_factor_generation_id');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(session('sso.grant'));
        $this->assertSame($generation, $user->fresh()->two_factor_generation_id);
        $this->assertSame(1, (int) $user->fresh()->auth_security_epoch);
        $this->assertNotNull($user->fresh()->two_factor_secret);
        DB::table('user_management')->where('id', $user->id)->increment('auth_security_epoch');
        $this->get(route('tech.profile.sso'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
