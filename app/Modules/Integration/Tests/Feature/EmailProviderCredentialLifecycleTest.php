<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Actions\ActivateEmailProviderCredential;
use App\Modules\Integration\Actions\CreateEmailProviderConnection;
use App\Modules\Integration\Actions\RevokeEmailProviderCredential;
use App\Modules\Integration\Actions\StageEmailProviderCredential;
use App\Modules\Integration\Actions\VerifyEmailProviderCredential;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Models\EmailProviderEvent;
use App\Modules\Integration\Services\EmailProviderConnectionVerifier;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderRuntimeFactory;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailProviderCredentialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private EmailProviderLifecycleFakeVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach ([
            EmailProviderManagementAuthorization::MANAGE_PERMISSION,
            EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION,
            EmailProviderManagementAuthorization::MAILBOX_SYNC_PERMISSION,
            EmailProviderManagementAuthorization::EMAIL_ACCOUNT_PERMISSION,
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->operator->givePermissionTo([
            EmailProviderManagementAuthorization::MANAGE_PERMISSION,
            EmailProviderManagementAuthorization::MAILBOX_SYNC_PERMISSION,
            EmailProviderManagementAuthorization::EMAIL_ACCOUNT_PERMISSION,
        ]);

        $this->verifier = new EmailProviderLifecycleFakeVerifier;
        $this->app->instance(EmailProviderConnectionVerifier::class, $this->verifier);
    }

    #[Test]
    public function exact_lifecycle_stages_verifies_activates_rotates_and_destroys_ciphertext_without_fallback(): void
    {
        $connection = $this->createProvider();
        $first = $connection->credentialVersions()->sole();

        $this->assertSame('standard.imap.993.implicit_tls', $connection->imap_endpoint_policy_id);
        $this->assertSame('standard.smtp.465.implicit_tls', $connection->smtp_endpoint_policy_id);
        $this->assertSame('password', $connection->imap_auth_type);
        $this->assertSame('password', $connection->smtp_auth_type);
        $this->assertSame(EmailProviderCredentialVersion::STATE_STAGED, $first->state);
        $this->assertCiphertextDoesNotContain($first, [
            'imap-user-canary', 'imap-secret-canary', 'smtp-user-canary', 'smtp-secret-canary',
        ]);
        $this->assertFalse(app(EmailProviderRuntimeFactory::class)->databaseReady($connection->getKey()));

        $verified = app(VerifyEmailProviderCredential::class)->execute($this->operator, $connection, $first);
        $this->assertNotNull($verified->verified_at);
        $this->assertSame(1, $this->verifier->calls);
        $this->assertNull($connection->fresh()->verified_credential_version);
        $this->assertFalse(app(EmailProviderRuntimeFactory::class)->databaseReady($connection->getKey()));

        $active = app(ActivateEmailProviderCredential::class)->execute(
            $this->operator,
            $connection->fresh(),
            $verified,
        );
        $this->assertSame(EmailProviderCredentialVersion::STATE_ACTIVE, $active->state);
        $this->assertTrue(app(EmailProviderRuntimeFactory::class)->databaseReady($connection->getKey()));

        $second = app(StageEmailProviderCredential::class)->execute(
            $this->operator,
            $connection->fresh(),
            [
                'imap_username' => '',
                'imap_secret' => 'imap-secret-rotated-canary',
                'smtp_username' => '',
                'smtp_secret' => 'smtp-secret-rotated-canary',
            ],
        );
        $second = app(VerifyEmailProviderCredential::class)->execute(
            $this->operator,
            $connection->fresh(),
            $second,
        );

        // Verifying a staged rotation never invalidates the current active
        // exact version before the separate activation action.
        $this->assertSame(1, (int) $connection->fresh()->verified_credential_version);
        $this->assertTrue(app(EmailProviderRuntimeFactory::class)->databaseReady($connection->getKey()));
        app(VerifyEmailProviderCredential::class)->execute($this->operator, $connection->fresh(), $second);
        $this->assertSame(2, $this->verifier->calls, 'Idempotent verification must not call the provider twice.');

        $second = app(ActivateEmailProviderCredential::class)->execute(
            $this->operator,
            $connection->fresh(),
            $second,
        );
        $first = $first->fresh();
        $this->assertSame(EmailProviderCredentialVersion::STATE_DESTROYED, $first->state);
        $this->assertFalse($first->hasCiphertext());
        $this->assertSame(EmailProviderCredentialVersion::STATE_ACTIVE, $second->state);

        $boundAccount = EmailAccount::query()->create([
            'address' => 'identity-binding@example.test',
            'from_name' => 'Identity Binding',
            'account_kind' => EmailAccount::KIND_SYSTEM,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => ['system'],
            'delete_policy' => 'local_only',
            'provider_integration_id' => $connection->getKey(),
            'provider_credential_source' => 'integration',
            'provider_binding_version' => 7,
            'provider_bound_at' => now(),
        ]);

        $revoked = app(RevokeEmailProviderCredential::class)->execute(
            $this->operator,
            $connection->fresh(),
            $second,
            'operator_security_revoke',
        );
        $this->assertSame(EmailProviderCredentialVersion::STATE_REVOKED, $revoked->state);
        $this->assertFalse($revoked->hasCiphertext());
        $this->assertSame('revoked', $connection->fresh()->status);
        $this->assertFalse(app(EmailProviderRuntimeFactory::class)->databaseReady($connection->getKey()));

        $versionCount = $connection->credentialVersions()->count();
        $eventCount = $connection->events()->count();
        $connectionLockVersion = (int) $connection->fresh()->lock_version;
        $this->assertSecurityReason(
            fn () => app(StageEmailProviderCredential::class)->execute(
                $this->operator,
                $connection->fresh(),
                [
                    'imap_username' => 'post-revoke-other-imap-user-canary',
                    'imap_secret' => 'post-revoke-other-imap-secret-canary',
                    'smtp_username' => 'post-revoke-other-smtp-user-canary',
                    'smtp_secret' => 'post-revoke-other-smtp-secret-canary',
                ],
            ),
            'credential_identity_change_requires_new_connection',
        );
        $this->assertSame($versionCount, $connection->credentialVersions()->count());
        $this->assertSame($eventCount, $connection->events()->count());
        $this->assertSame($connectionLockVersion, (int) $connection->fresh()->lock_version);
        $this->assertSame(7, (int) $boundAccount->fresh()->provider_binding_version);
        $this->assertSame($connection->getKey(), $boundAccount->fresh()->provider_integration_id);

        $events = EmailProviderEvent::query()->get()->toJson();
        foreach ([
            '8.8.8.8', '1.1.1.1', 'imap-user-canary', 'imap-secret-canary',
            'smtp-user-canary', 'smtp-secret-canary', 'rotated-canary',
        ] as $canary) {
            $this->assertStringNotContainsString($canary, $events);
        }
    }

    #[Test]
    public function username_identity_change_and_unsupported_auth_are_rejected_before_activation(): void
    {
        $connection = $this->createProvider();
        $versionCount = $connection->credentialVersions()->count();
        $eventCount = $connection->events()->count();
        $this->assertSecurityReason(function () use ($connection): void {
            app(StageEmailProviderCredential::class)->execute(
                $this->operator,
                $connection->fresh(),
                [
                    'imap_username' => 'pre-activation-other-imap-user-canary',
                    'imap_secret' => 'pre-activation-other-imap-secret-canary',
                    'smtp_username' => 'pre-activation-other-smtp-user-canary',
                    'smtp_secret' => 'pre-activation-other-smtp-secret-canary',
                ],
            );
        }, 'credential_identity_change_requires_new_connection');
        $this->assertSame($versionCount, $connection->credentialVersions()->count());
        $this->assertSame($eventCount, $connection->events()->count());

        $first = $connection->credentialVersions()->sole();
        $first = app(VerifyEmailProviderCredential::class)->execute($this->operator, $connection, $first);
        app(ActivateEmailProviderCredential::class)->execute($this->operator, $connection->fresh(), $first);

        $this->assertSecurityReason(function () use ($connection): void {
            app(StageEmailProviderCredential::class)->execute(
                $this->operator,
                $connection->fresh(),
                [
                    'imap_username' => 'different-user-canary',
                    'imap_secret' => 'new-secret-canary',
                    'smtp_username' => 'smtp-user-canary',
                    'smtp_secret' => 'new-smtp-secret-canary',
                ],
            );
        }, 'credential_identity_change_requires_new_connection');
        $this->assertSame(1, $connection->credentialVersions()->count());

        $before = EmailProviderConnection::query()->count();
        $input = $this->providerInput();
        $input['name'] = 'Unsupported OAuth provider';
        $input['imap_auth_type'] = 'oauth2';
        $this->assertSecurityReason(
            fn () => app(CreateEmailProviderConnection::class)->execute($this->operator, $input),
            'authentication_type_not_supported',
        );
        $this->assertSame($before, EmailProviderConnection::query()->count());
    }

    #[Test]
    public function verification_claim_is_one_owner_and_provider_failures_are_severed_from_diagnostics(): void
    {
        $connection = $this->createProvider();
        $version = $connection->credentialVersions()->sole();
        $connection->forceFill([
            'verification_claim_token' => 'a0af29bb-08e1-46a6-a2e9-1aa68c08b364',
            'verification_claim_configuration_version' => 1,
            'verification_claim_credential_version' => 1,
            'verification_claim_expires_at' => now()->addMinute(),
        ])->save();

        $this->assertSecurityReason(
            fn () => app(VerifyEmailProviderCredential::class)->execute(
                $this->operator,
                $connection->fresh(),
                $version,
            ),
            'verification_in_progress',
        );
        $this->assertSame(0, $this->verifier->calls);

        $connection->forceFill(['verification_claim_expires_at' => now()->subSecond()])->save();
        $this->verifier->failure = 'raw provider response host=8.8.8.8 user=imap-user-canary secret=imap-secret-canary';
        try {
            app(VerifyEmailProviderCredential::class)->execute(
                $this->operator,
                $connection->fresh(),
                $version,
            );
            $this->fail('Provider verification failure must be sanitized.');
        } catch (EmailProviderSecurityException $exception) {
            $this->assertSame('provider_verification_failed', $exception->reasonCode);
            $this->assertNull($exception->getPrevious());
            $diagnostic = (string) $exception.print_r($exception->getTrace(), true);
            foreach (['8.8.8.8', 'imap-user-canary', 'imap-secret-canary', 'raw provider response'] as $canary) {
                $this->assertStringNotContainsString($canary, $diagnostic);
            }
        }

        $connection = $connection->fresh();
        $this->assertNull($connection->verification_claim_token);
        $this->assertSame('verification_failed', $connection->last_verification_code);
        $this->assertSame('verification_failed', $version->fresh()->verification_code);
    }

    #[Test]
    public function verification_lifecycle_barrier_blocks_revoke_until_the_probe_finishes(): void
    {
        $connection = $this->createProvider();
        $version = $connection->credentialVersions()->sole();
        $revokeReason = null;
        $this->verifier->beforeReturn = function () use ($connection, $version, &$revokeReason): void {
            try {
                app(RevokeEmailProviderCredential::class)->execute(
                    $this->operator,
                    $connection->fresh(),
                    $version->fresh(),
                    'racing_revoke',
                );
            } catch (EmailProviderSecurityException $exception) {
                $revokeReason = $exception->reasonCode;
            }
        };

        $verified = app(VerifyEmailProviderCredential::class)->execute(
            $this->operator,
            $connection,
            $version,
        );

        $this->assertSame('provider_lifecycle_locked', $revokeReason);
        $this->assertSame(EmailProviderCredentialVersion::STATE_STAGED, $verified->state);
        $this->assertTrue($verified->hasCiphertext());
        $this->assertSame(1, $this->verifier->calls);
    }

    #[Test]
    public function provider_events_are_append_only_at_both_model_and_database_boundaries(): void
    {
        $this->createProvider();
        $event = EmailProviderEvent::query()->firstOrFail();

        try {
            DB::table('integration_email_provider_events')
                ->where('id', $event->id)
                ->update(['reason_code' => 'tampered']);
            $this->fail('Direct database updates must not alter provider lifecycle history.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('email_provider_events_are_append_only', $exception->getMessage());
        }

        try {
            DB::table('integration_email_provider_events')->where('id', $event->id)->delete();
            $this->fail('Direct database deletes must not remove provider lifecycle history.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('email_provider_events_are_append_only', $exception->getMessage());
        }

        $this->assertDatabaseHas('integration_email_provider_events', [
            'id' => $event->id,
            'reason_code' => $event->reason_code,
        ]);
    }

    #[Test]
    public function authorization_private_trust_and_database_readiness_fail_closed(): void
    {
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        try {
            app(CreateEmailProviderConnection::class)->execute($unauthorized, $this->providerInput());
            $this->fail('Provider creation requires explicit management and sync permissions.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('integration_email_provider_connections', 0);
        }

        config()->set('email_provider_security.trusted_private_cidrs.internal_mail', ['10.20.0.0/16']);
        $private = $this->providerInput();
        $private['name'] = 'Private mail cluster';
        $private['imap_host'] = '10.20.1.10';
        $private['smtp_host'] = '10.20.1.11';
        $private['trust_mode'] = 'trusted_private';
        $private['trusted_cidr_name'] = 'internal_mail';
        $private['private_endpoint_reason'] = 'Approved internal cluster canary';

        try {
            app(CreateEmailProviderConnection::class)->execute($this->operator, $private);
            $this->fail('Private endpoints require the distinct Superuser permission.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('integration_email_provider_connections', 0);
        }

        $this->operator->givePermissionTo(EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION);
        $connection = app(CreateEmailProviderConnection::class)->execute($this->operator->fresh(), $private);
        $this->assertSame('trusted_private', $connection->trust_mode);
        $this->assertSame('internal_mail', $connection->trusted_cidr_name);

        $version = $connection->credentialVersions()->sole();
        $version = app(VerifyEmailProviderCredential::class)->execute($this->operator->fresh(), $connection, $version);
        $version = app(ActivateEmailProviderCredential::class)->execute(
            $this->operator->fresh(),
            $connection->fresh(),
            $version,
        );

        $this->operator->revokePermissionTo(EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION);
        $versionCount = $connection->credentialVersions()->count();
        $eventCount = $connection->events()->count();

        try {
            app(StageEmailProviderCredential::class)->execute(
                $this->operator->fresh(),
                $connection->fresh(),
                [
                    'imap_username' => '',
                    'imap_secret' => 'private-denied-imap-secret-canary',
                    'smtp_username' => '',
                    'smtp_secret' => 'private-denied-smtp-secret-canary',
                ],
            );
            $this->fail('Every private-provider rotation requires the distinct private endpoint permission.');
        } catch (AuthorizationException) {
            $this->assertSame($versionCount, $connection->credentialVersions()->count());
            $this->assertSame($eventCount, $connection->events()->count());
        }

        $this->operator->givePermissionTo(EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION);
        $stagedRotation = app(StageEmailProviderCredential::class)->execute(
            $this->operator->fresh(),
            $connection->fresh(),
            [
                'imap_username' => '',
                'imap_secret' => 'private-superuser-imap-secret-canary',
                'smtp_username' => '',
                'smtp_secret' => 'private-superuser-smtp-secret-canary',
            ],
        );
        $this->assertSame(EmailProviderCredentialVersion::STATE_STAGED, $stagedRotation->state);
        $this->assertSame($versionCount + 1, $connection->credentialVersions()->count());
        $this->assertSame($eventCount + 1, $connection->events()->count());

        // Database-only readiness validates metadata and non-empty ciphertext;
        // it deliberately does not decrypt during selectors or UI queries.
        $version->forceFill(['imap_secret_encrypted' => 'invalid-but-nonempty-ciphertext'])->save();
        $this->assertTrue(app(EmailProviderRuntimeFactory::class)->databaseReady($connection->getKey()));
        $this->assertSecurityReason(
            fn () => app(EmailProviderRuntimeFactory::class)->active($connection->getKey()),
            'credential_decryption_failed',
        );

        $connection->forceFill(['verified_credential_version' => 999])->save();
        $this->assertFalse(app(EmailProviderRuntimeFactory::class)->databaseReady($connection->getKey()));
    }

    private function createProvider(): EmailProviderConnection
    {
        return app(CreateEmailProviderConnection::class)->execute(
            $this->operator,
            $this->providerInput(),
        );
    }

    /** @return array<string, mixed> */
    private function providerInput(): array
    {
        return [
            'name' => 'Lifecycle provider',
            'imap_host' => '8.8.8.8',
            'imap_port' => 993,
            'imap_transport' => 'implicit_tls',
            'imap_auth_type' => 'password',
            'imap_username' => 'imap-user-canary',
            'imap_secret' => 'imap-secret-canary',
            'smtp_host' => '1.1.1.1',
            'smtp_port' => 465,
            'smtp_transport' => 'implicit_tls',
            'smtp_auth_type' => 'password',
            'smtp_username' => 'smtp-user-canary',
            'smtp_secret' => 'smtp-secret-canary',
            'trust_mode' => 'public',
            'trusted_cidr_name' => null,
            'private_endpoint_reason' => null,
        ];
    }

    /** @param list<string> $canaries */
    private function assertCiphertextDoesNotContain(
        EmailProviderCredentialVersion $version,
        array $canaries,
    ): void {
        $ciphertext = implode('|', [
            $version->imap_username_encrypted,
            $version->imap_secret_encrypted,
            $version->smtp_username_encrypted,
            $version->smtp_secret_encrypted,
        ]);
        foreach ($canaries as $canary) {
            $this->assertStringNotContainsString($canary, $ciphertext);
        }
    }

    private function assertSecurityReason(callable $callback, string $reasonCode): void
    {
        try {
            $callback();
            $this->fail('The Email provider security operation should have failed closed.');
        } catch (EmailProviderSecurityException $exception) {
            $this->assertSame($reasonCode, $exception->reasonCode);
            $this->assertNull($exception->getPrevious());
        }
    }
}

final class EmailProviderLifecycleFakeVerifier extends EmailProviderConnectionVerifier
{
    public int $calls = 0;

    public ?string $failure = null;

    public ?Closure $beforeReturn = null;

    public function __construct() {}

    public function verify(#[\SensitiveParameter] EmailProviderRuntimeCredentials $runtime): array
    {
        $this->calls++;

        if ($this->beforeReturn instanceof Closure) {
            ($this->beforeReturn)();
        }

        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return [
            'capabilities' => [
                'imap' => true,
                'smtp' => true,
                'folder_discovery' => false,
            ],
        ];
    }
}
