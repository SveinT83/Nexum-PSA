<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Models\EmailProviderEvent;
use App\Modules\Integration\Services\EmailProviderCredentialCipher;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Providers\TelescopeServiceProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailProviderAdministrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $superuser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->app->register(TelescopeServiceProvider::class);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
        $this->superuser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->superuser->assignRole('Superuser');
    }

    #[Test]
    public function role_permissions_and_telescope_gate_keep_private_endpoints_and_cross_domain_telemetry_superuser_only(): void
    {
        $this->assertTrue(Role::findByName('Admin')->hasPermissionTo('integration.email_provider_manage'));
        $this->assertTrue(Role::findByName('Admin')->hasPermissionTo('email.mailbox_sync_manage'));
        $this->assertTrue(Role::findByName('Admin')->hasPermissionTo('email.account_manage'));
        $this->assertFalse(Role::findByName('Admin')->hasPermissionTo('integration.email_private_endpoint_manage'));
        $this->assertFalse(Role::findByName('Admin')->hasPermissionTo('system.telescope_view'));
        $this->assertTrue(Role::findByName('Superuser')->hasPermissionTo('integration.email_private_endpoint_manage'));
        $this->assertTrue(Role::findByName('Superuser')->hasPermissionTo('system.telescope_view'));
        $this->assertFalse(Gate::forUser($this->admin)->allows('viewTelescope'));
        $this->assertTrue(Gate::forUser($this->superuser)->allows('viewTelescope'));
    }

    #[Test]
    public function email_account_pages_offer_one_direct_imap_smtp_form_without_provider_or_migration_workflow(): void
    {
        $provider = $this->activeProvider('Hidden internal provider', 'public');
        Role::findByName('Admin')->revokePermissionTo(
            EmailProviderManagementAuthorization::MANAGE_PERMISSION,
        );
        $admin = $this->admin->fresh();

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.email.accounts'))
            ->assertOk()
            ->assertSee('data-telemetry="click_add_account"', false)
            ->assertDontSee('No active verified Email provider is available.')
            ->assertDontSee('Legacy Migration')
            ->assertDontSee('Staged');

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.email.accounts.create'))
            ->assertOk()
            ->assertSee('id="imap_host"', false)
            ->assertSee('id="imap_secret"', false)
            ->assertSee('id="smtp_host"', false)
            ->assertSee('id="smtp_secret"', false)
            ->assertSee('Save and test connection')
            ->assertDontSee('provider_integration_id')
            ->assertDontSee('Hidden internal provider')
            ->assertDontSee('Legacy Migration')
            ->assertDontSee('Staged');

        $this->actingAs($this->superuser)
            ->get(route('tech.admin.settings.email.accounts.create'))
            ->assertOk()
            ->assertDontSee('Hidden internal provider')
            ->assertDontSee('public-host-canary.example')
            ->assertDontSee('username-ui-canary')
            ->assertDontSee('secret-ui-canary');

        $this->assertTrue($provider->fresh()->activeCredentialVersion->hasCiphertext());
    }

    #[Test]
    public function internal_provider_pages_redirect_to_the_email_account_workflow(): void
    {
        $provider = $this->activeProvider('Redirected internal provider', 'public');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.email-providers.index'))
            ->assertRedirect(route('tech.admin.settings.email.accounts'));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.email-providers.create'))
            ->assertRedirect(route('tech.admin.settings.email.accounts.create'));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.email-providers.show', $provider->getKey()))
            ->assertRedirect(route('tech.admin.settings.email.accounts'));
    }

    #[Test]
    #[DataProvider('missingEmailAccountConfigurationPermissionProvider')]
    public function email_account_create_fails_closed_without_each_account_configuration_permission(
        string $missingPermission,
    ): void {
        Role::findByName('Admin')->revokePermissionTo($missingPermission);
        $admin = $this->admin->fresh();

        $this->assertFalse($admin->can($missingPermission));
        $before = $this->emailProviderPersistenceFingerprint();

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.email.accounts.create'))
            ->assertForbidden();

        $this->assertSame($before, $this->emailProviderPersistenceFingerprint());
    }

    /** @return iterable<string, array{string}> */
    public static function missingEmailAccountConfigurationPermissionProvider(): iterable
    {
        yield 'email account management permission' => ['email.account_manage'];
        yield 'mailbox sync permission' => ['email.mailbox_sync_manage'];
    }

    #[Test]
    public function validation_never_flashes_provider_endpoints_credentials_or_private_trust_material(): void
    {
        $public = $this->activeProvider('Public validation provider', 'public');
        $payload = array_merge($this->accountPayload($public), [
            'address' => 'not-an-email',
            'imap_host' => 'old-input-host-canary.example',
            'imap_port' => 993,
            'imap_encryption' => 'implicit_tls',
            'imap_username' => 'old-input-user-canary',
            'imap_secret' => 'old-input-secret-canary',
            'smtp_host' => 'old-input-smtp-host-canary.example',
            'smtp_port' => 587,
            'smtp_encryption' => 'starttls',
            'smtp_username' => 'old-input-smtp-user-canary',
            'smtp_secret' => 'old-input-smtp-secret-canary',
            'private_endpoint_reason' => 'old-input-reason-canary',
            'trusted_cidr_name' => 'old-input-cidr-canary',
        ]);

        $this->actingAs($this->admin)
            ->from(route('tech.admin.settings.email.accounts.create'))
            ->post(route('tech.admin.settings.email.accounts.store'), $payload)
            ->assertRedirect(route('tech.admin.settings.email.accounts.create'))
            ->assertSessionHasErrors('address');

        $oldInput = session()->get('_old_input', []);
        foreach ([
            'imap_host', 'imap_username', 'imap_secret', 'smtp_secret',
            'private_endpoint_reason', 'trusted_cidr_name',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $oldInput);
        }
        $encoded = json_encode($oldInput, JSON_THROW_ON_ERROR);
        foreach ([
            'old-input-host-canary.example', 'old-input-user-canary', 'old-input-secret-canary',
            'old-input-smtp-secret-canary', 'old-input-reason-canary', 'old-input-cidr-canary',
        ] as $canary) {
            $this->assertStringNotContainsString($canary, $encoded);
        }

        $this->actingAs($this->admin)
            ->from(route('tech.admin.system.integrations.email-providers.create'))
            ->post(route('tech.admin.system.integrations.email-providers.store'), [
                'name' => '',
                'imap_host' => 'provider-form-host-canary.example',
                'imap_port' => 993,
                'imap_transport' => 'implicit_tls',
                'imap_username' => 'provider-form-user-canary',
                'imap_secret' => 'provider-form-secret-canary',
                'smtp_host' => 'provider-form-smtp-host-canary.example',
                'smtp_port' => 465,
                'smtp_transport' => 'implicit_tls',
                'smtp_username' => 'provider-form-smtp-user-canary',
                'smtp_secret' => 'provider-form-smtp-secret-canary',
                'trust_mode' => 'trusted_private',
                'trusted_cidr_name' => 'provider-form-cidr-canary',
                'private_endpoint_reason' => 'provider-form-reason-canary',
            ])
            ->assertSessionHasErrors('name');

        $encoded = json_encode(session()->get('_old_input', []), JSON_THROW_ON_ERROR);
        foreach ([
            'provider-form-host-canary.example', 'provider-form-user-canary', 'provider-form-secret-canary',
            'provider-form-smtp-host-canary.example', 'provider-form-smtp-user-canary',
            'provider-form-smtp-secret-canary', 'provider-form-cidr-canary', 'provider-form-reason-canary',
        ] as $canary) {
            $this->assertStringNotContainsString($canary, $encoded);
        }
    }

    #[Test]
    public function generic_integration_toggle_cannot_mutate_multi_record_email_provider_lifecycle(): void
    {
        $provider = $this->activeProvider('Untoggleable lifecycle provider', 'public');
        $before = Integration::query()->findOrFail($provider->getKey())->status;

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.toggle'), [
                'type' => 'email_provider',
                'name' => 'Attempted generic mutation',
            ])
            ->assertSessionHasErrors('type');

        $this->assertSame($before, Integration::query()->findOrFail($provider->getKey())->status);
        $this->assertDatabaseCount('integration_email_provider_connections', 1);
    }

    #[Test]
    public function private_provider_rotation_is_reauthorized_inside_the_action_for_ui_and_direct_callers(): void
    {
        $private = $this->activeProvider('Private rotation provider', 'trusted_private');
        $versionCount = $private->credentialVersions()->count();
        $eventCount = $private->events()->count();
        $payload = [
            'imap_secret' => 'denied-private-imap-secret-canary',
            'smtp_secret' => 'denied-private-smtp-secret-canary',
        ];

        $this->actingAs($this->admin)
            ->post(
                route('tech.admin.system.integrations.email-providers.credentials.stage', $private->getKey()),
                $payload,
            )
            ->assertForbidden();

        $this->assertSame($versionCount, $private->credentialVersions()->count());
        $this->assertSame($eventCount, $private->events()->count());

        $this->actingAs($this->superuser)
            ->post(
                route('tech.admin.system.integrations.email-providers.credentials.stage', $private->getKey()),
                [
                    'imap_secret' => 'allowed-private-imap-secret-canary',
                    'smtp_secret' => 'allowed-private-smtp-secret-canary',
                ],
            )
            ->assertRedirect();

        $this->assertSame($versionCount + 1, $private->credentialVersions()->count());
        $this->assertSame($eventCount + 1, $private->events()->count());
    }

    private function activeProvider(string $name, string $trustMode): EmailProviderConnection
    {
        $id = (string) Str::uuid();
        Integration::query()->create([
            'id' => $id,
            'name' => $name,
            'type' => 'email_provider',
            'status' => 'active',
            'config' => ['provider_status' => 'active'],
            'secrets' => null,
            'is_healthy' => true,
        ]);
        $private = $trustMode === 'trusted_private';
        $connection = EmailProviderConnection::query()->create([
            'integration_id' => $id,
            'status' => 'active',
            'configuration_version' => 1,
            'verified_configuration_version' => 1,
            'verified_credential_version' => 1,
            'imap_host' => $private ? 'private-host-canary.example' : 'public-host-canary.example',
            'imap_port' => 993,
            'imap_transport' => 'implicit_tls',
            'imap_endpoint_policy_id' => 'standard.imap.993.implicit_tls',
            'imap_auth_type' => 'password',
            'smtp_host' => $private ? 'private-smtp-host-canary.example' : 'public-smtp-host-canary.example',
            'smtp_port' => 465,
            'smtp_transport' => 'implicit_tls',
            'smtp_endpoint_policy_id' => 'standard.smtp.465.implicit_tls',
            'smtp_auth_type' => 'password',
            'trust_mode' => $trustMode,
            'trusted_cidr_name' => $private ? 'internal_mail' : null,
            'private_endpoint_reason' => $private ? 'Reviewed private UI test' : null,
            'capabilities' => ['imap' => true, 'smtp' => true],
            'last_verification_code' => 'verified',
            'last_verified_at' => now(),
            'created_by' => $this->superuser->id,
            'updated_by' => $this->superuser->id,
        ]);
        $ciphertext = app(EmailProviderCredentialCipher::class)->encrypt([
            'imap_username' => 'username-ui-canary',
            'imap_secret' => 'secret-ui-canary',
            'smtp_username' => 'smtp-username-ui-canary',
            'smtp_secret' => 'smtp-secret-ui-canary',
        ]);
        $version = EmailProviderCredentialVersion::query()->create([
            'provider_integration_id' => $id,
            'version' => 1,
            'state' => EmailProviderCredentialVersion::STATE_ACTIVE,
            ...$ciphertext,
            'credential_fingerprint' => hash('sha256', $id),
            'verified_configuration_version' => 1,
            'verification_code' => 'verified',
            'staged_by' => $this->superuser->id,
            'verified_by' => $this->superuser->id,
            'activated_by' => $this->superuser->id,
            'staged_at' => now(),
            'verified_at' => now(),
            'activated_at' => now(),
        ]);
        $connection->forceFill(['active_credential_version_id' => $version->id])->save();

        return $connection->fresh('activeCredentialVersion');
    }

    /** @return array<string, mixed> */
    private function providerFormPayload(): array
    {
        return [
            'name' => 'Account workflow provider',
            'imap_host' => '8.8.8.8',
            'imap_port' => 993,
            'imap_transport' => 'implicit_tls',
            'imap_username' => 'account-flow-imap-user-canary',
            'imap_secret' => 'account-flow-imap-secret-canary',
            'smtp_host' => '1.1.1.1',
            'smtp_port' => 465,
            'smtp_transport' => 'implicit_tls',
            'smtp_username' => 'account-flow-smtp-user-canary',
            'smtp_secret' => 'account-flow-smtp-secret-canary',
            'trust_mode' => 'public',
            'trusted_cidr_name' => null,
            'private_endpoint_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function accountPayload(EmailProviderConnection $connection): array
    {
        return [
            'address' => 'provider-account@example.test',
            'description' => 'Integration account',
            'from_name' => 'Provider Account',
            'account_kind' => EmailAccount::KIND_SHARED,
            'owner_id' => null,
            'is_active' => 1,
            'is_global_default' => 0,
            'defaults_for' => [],
            'ticket_ingress_enabled' => 0,
            'delete_policy' => 'local_only',
            'provider_integration_id' => $connection->getKey(),
        ];
    }

    /** @return array<string, list<string>> */
    private function emailProviderPersistenceFingerprint(): array
    {
        $fingerprint = static fn (array $attributes): string => hash(
            'sha256',
            json_encode($attributes, JSON_THROW_ON_ERROR),
        );

        return [
            'accounts' => EmailAccount::query()
                ->orderBy('id')
                ->get()
                ->map(fn (EmailAccount $account): string => $fingerprint($account->getAttributes()))
                ->all(),
            'integrations' => Integration::query()
                ->where('type', 'email_provider')
                ->orderBy('id')
                ->get()
                ->map(fn (Integration $integration): string => $fingerprint($integration->getAttributes()))
                ->all(),
            'provider_connections' => EmailProviderConnection::query()
                ->orderBy('integration_id')
                ->get()
                ->map(fn (EmailProviderConnection $connection): string => $fingerprint($connection->getAttributes()))
                ->all(),
            'provider_events' => EmailProviderEvent::query()
                ->orderBy('id')
                ->get()
                ->map(fn (EmailProviderEvent $event): string => $fingerprint($event->getAttributes()))
                ->all(),
            'credential_versions' => EmailProviderCredentialVersion::query()
                ->orderBy('id')
                ->get()
                ->map(fn (EmailProviderCredentialVersion $version): string => $fingerprint($version->getAttributes()))
                ->all(),
        ];
    }
}
