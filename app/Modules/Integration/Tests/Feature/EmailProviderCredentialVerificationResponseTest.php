<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Actions\CreateEmailProviderConnection;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Models\EmailProviderEvent;
use App\Modules\Integration\Services\EmailProviderConnectionVerifier;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EmailProviderCredentialVerificationResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private EmailProviderVerificationResponseFakeVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->operator->assignRole('Admin');
        $this->verifier = new EmailProviderVerificationResponseFakeVerifier;
        $this->app->instance(EmailProviderConnectionVerifier::class, $this->verifier);
    }

    #[Test]
    #[DataProvider('safeFailureMessagesProvider')]
    public function verify_route_redirects_with_an_accessible_safe_failure(
        string $reasonCode,
        string $expectedMessage,
    ): void {
        $connection = $this->createProvider();
        $credential = $connection->credentialVersions()->sole();
        $this->verifier->reasonCode = $reasonCode;

        $response = $this->actingAs($this->operator)
            ->from(route('tech.admin.system.integrations.email-providers.show', $connection->getKey()))
            ->post(route(
                'tech.admin.system.integrations.email-providers.credentials.verify',
                [$connection->getKey(), $credential->version],
            ));

        $response
            ->assertRedirect(route('tech.admin.system.integrations.email-providers.show', $connection->getKey()))
            ->assertSessionHas('error', $expectedMessage);

        $this->followRedirects($response)
            ->assertOk()
            ->assertSee($expectedMessage)
            ->assertSee('alert alert-danger', false)
            ->assertSee('role="alert"', false);

        $this->assertSame('staged', $connection->fresh()->status);
        $this->assertSame('verification_failed', $connection->fresh()->last_verification_code);
        $this->assertSame(EmailProviderCredentialVersion::STATE_STAGED, $credential->fresh()->state);
        $this->assertSame('verification_failed', $credential->fresh()->verification_code);
        $this->assertDatabaseHas('integration_email_provider_events', [
            'provider_integration_id' => $connection->getKey(),
            'event_type' => 'credential_verification_failed',
            'reason_code' => 'provider_check_failed',
        ]);
    }

    #[Test]
    public function verify_route_severs_raw_provider_failures_from_the_response_and_session(): void
    {
        $connection = $this->createProvider();
        $credential = $connection->credentialVersions()->sole();
        $this->verifier->rawFailure = 'raw-provider-canary host=8.8.8.8 user=operator-route-canary secret=secret-canary';

        $response = $this->actingAs($this->operator)
            ->from(route('tech.admin.system.integrations.email-providers.show', $connection->getKey()))
            ->post(route(
                'tech.admin.system.integrations.email-providers.credentials.verify',
                [$connection->getKey(), $credential->version],
            ));

        $response->assertSessionHas(
            'error',
            'Nexum could not verify the provider safely. Check the staged credentials, TLS settings, and reachability before retrying Verify.',
        );

        $page = $this->followRedirects($response)->assertOk();
        foreach (['raw-provider-canary', '8.8.8.8', 'operator-route-canary', 'secret-canary'] as $canary) {
            $page->assertDontSee($canary);
            $this->assertStringNotContainsString(
                $canary,
                json_encode(session()->all(), JSON_THROW_ON_ERROR),
            );
        }
    }

    #[Test]
    public function verify_route_reports_an_existing_claim_without_changing_staged_state_or_audit(): void
    {
        $connection = $this->createProvider();
        $credential = $connection->credentialVersions()->sole();
        $connection->forceFill([
            'verification_claim_token' => '81c5831d-1964-4400-94dd-af94f022b172',
            'verification_claim_configuration_version' => $connection->configuration_version,
            'verification_claim_credential_version' => $credential->version,
            'verification_claim_expires_at' => now()->addMinute(),
        ])->save();
        $eventCount = EmailProviderEvent::query()->count();

        $response = $this->actingAs($this->operator)
            ->from(route('tech.admin.system.integrations.email-providers.show', $connection->getKey()))
            ->post(route(
                'tech.admin.system.integrations.email-providers.credentials.verify',
                [$connection->getKey(), $credential->version],
            ));

        $response->assertSessionHas(
            'error',
            'Another provider verification or lifecycle operation is in progress. Wait for it to finish before retrying Verify.',
        );
        $this->assertSame($eventCount, EmailProviderEvent::query()->count());
        $this->assertSame(EmailProviderCredentialVersion::STATE_STAGED, $credential->fresh()->state);
        $this->assertSame('staged', $connection->fresh()->status);
        $this->assertSame(0, $this->verifier->calls);
    }

    #[Test]
    public function verify_route_reports_a_stale_credential_snapshot_without_provider_io(): void
    {
        $connection = $this->createProvider();
        $credential = $connection->credentialVersions()->sole();
        $credential->forceFill(['state' => EmailProviderCredentialVersion::STATE_REVOKED])->save();

        $response = $this->actingAs($this->operator)
            ->from(route('tech.admin.system.integrations.email-providers.show', $connection->getKey()))
            ->post(route(
                'tech.admin.system.integrations.email-providers.credentials.verify',
                [$connection->getKey(), $credential->version],
            ));

        $response->assertSessionHas(
            'error',
            'The staged credential or provider configuration changed before verification completed. Reload the provider page and verify the current staged version.',
        );
        $this->assertSame(0, $this->verifier->calls);
        $this->assertSame(EmailProviderCredentialVersion::STATE_REVOKED, $credential->fresh()->state);
        $this->assertSame('staged', $connection->fresh()->status);
    }

    /** @return iterable<string, array{string, string}> */
    public static function safeFailureMessagesProvider(): iterable
    {
        yield 'endpoint policy' => [
            'transport_mismatch',
            'The provider endpoint, port, transport, or approved trust policy does not match the allowed configuration. Review the staged connection settings before retrying Verify.',
        ];
        yield 'DNS and address policy' => [
            'dns_answer_set_denied',
            'The provider address was rejected by DNS or address policy. Review the configured hostname and approved network scope before retrying Verify.',
        ];
        yield 'TLS and reachability' => [
            'provider_connection_failed',
            'Nexum could not establish a trusted TLS connection to the provider. Check certificate trust, transport, and provider reachability before retrying Verify.',
        ];
        yield 'authentication' => [
            'provider_authentication_rejected',
            'The provider rejected the staged credentials. Check the IMAP and SMTP credentials before retrying Verify.',
        ];
        yield 'bounded timeout' => [
            'provider_verification_deadline_exceeded',
            'Provider verification timed out. Check provider reachability, wait for any active attempt to finish, and retry Verify.',
        ];
    }

    private function createProvider(): EmailProviderConnection
    {
        return app(CreateEmailProviderConnection::class)->execute($this->operator, [
            'name' => 'Verification response provider',
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
        ]);
    }
}

final class EmailProviderVerificationResponseFakeVerifier extends EmailProviderConnectionVerifier
{
    public int $calls = 0;

    public ?string $reasonCode = null;

    public ?string $rawFailure = null;

    public function __construct() {}

    public function verify(#[\SensitiveParameter] EmailProviderRuntimeCredentials $runtime): array
    {
        $this->calls++;

        if ($this->reasonCode !== null) {
            throw new EmailProviderSecurityException($this->reasonCode);
        }

        if ($this->rawFailure !== null) {
            throw new RuntimeException($this->rawFailure);
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
