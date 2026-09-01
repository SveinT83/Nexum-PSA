<?php

namespace App\Modules\Email\Tests\Feature;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Services\EmailProviderCredentialCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EmailAccountOwnedCredentialCutoverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_promotes_an_exact_verified_binding_and_destroys_the_duplicate_secret_store(): void
    {
        [$account, $connection, $credential] = $this->providerBoundAccount();

        $this->runCutover();

        $account->refresh();
        $connection->refresh();
        $credential->refresh();

        $this->assertSame('account', $account->provider_credential_source);
        $this->assertNull($account->provider_integration_id);
        $this->assertSame(2, $account->provider_binding_version);
        $this->assertSame('imap.cutover.example', $account->imap_host);
        $this->assertSame('smtp.cutover.example', $account->smtp_host);
        $this->assertSame('mailbox@example.test', $account->imap_username);
        $this->assertSame('mailbox@example.test', $account->smtp_username);
        $this->assertSame('imap-password', Crypt::decryptString($account->getAttribute('imap_secret')));
        $this->assertSame('smtp-password', Crypt::decryptString($account->getAttribute('smtp_secret')));
        $this->assertTrue($account->is_active);
        $this->assertSame('OK', $account->last_test_result);

        $this->assertSame('revoked', $connection->status);
        $this->assertNull($connection->active_credential_version_id);
        $this->assertSame(EmailProviderCredentialVersion::STATE_DESTROYED, $credential->state);
        $this->assertFalse($credential->hasCiphertext());
        $this->assertSame('disabled', $connection->integration()->value('status'));
    }

    #[Test]
    public function it_fails_closed_without_destroying_secrets_when_the_binding_is_not_exactly_verified(): void
    {
        [$account, $connection, $credential] = $this->providerBoundAccount();
        $credential->forceFill(['verification_code' => 'authentication_failed'])->save();

        try {
            $this->runCutover();
            $this->fail('The cutover should reject a credential that is not exactly verified.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not exactly verified', $exception->getMessage());
        }

        $this->assertSame('integration', $account->fresh()->provider_credential_source);
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertSame(EmailProviderCredentialVersion::STATE_ACTIVE, $credential->fresh()->state);
        $this->assertTrue($credential->fresh()->hasCiphertext());
    }

    /** @return array{EmailAccount, EmailProviderConnection, EmailProviderCredentialVersion} */
    private function providerBoundAccount(): array
    {
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $providerId = (string) Str::uuid();
        Integration::query()->create([
            'id' => $providerId,
            'name' => 'Cutover fixture',
            'type' => 'email_provider',
            'status' => 'active',
            'is_healthy' => true,
        ]);
        $connection = EmailProviderConnection::query()->create([
            'integration_id' => $providerId,
            'status' => 'active',
            'configuration_version' => 1,
            'verified_configuration_version' => 1,
            'verified_credential_version' => 1,
            'imap_host' => 'imap.cutover.example',
            'imap_port' => 993,
            'imap_transport' => 'implicit_tls',
            'imap_endpoint_policy_id' => 'standard.imap.993.implicit_tls',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.cutover.example',
            'smtp_port' => 587,
            'smtp_transport' => 'starttls',
            'smtp_endpoint_policy_id' => 'standard.smtp.587.starttls',
            'smtp_auth_type' => 'password',
            'trust_mode' => 'public',
            'last_verification_code' => 'verified',
            'last_verified_at' => now(),
            'created_by' => $operator->id,
            'updated_by' => $operator->id,
        ]);
        $credential = EmailProviderCredentialVersion::query()->create([
            'provider_integration_id' => $providerId,
            'version' => 1,
            'state' => EmailProviderCredentialVersion::STATE_ACTIVE,
            ...app(EmailProviderCredentialCipher::class)->encrypt([
                'imap_username' => 'mailbox@example.test',
                'imap_secret' => 'imap-password',
                'smtp_username' => 'mailbox@example.test',
                'smtp_secret' => 'smtp-password',
            ]),
            'credential_fingerprint' => hash('sha256', $providerId),
            'verified_configuration_version' => 1,
            'verification_code' => 'verified',
            'staged_by' => $operator->id,
            'verified_by' => $operator->id,
            'activated_by' => $operator->id,
            'staged_at' => now(),
            'verified_at' => now(),
            'activated_at' => now(),
        ]);
        $connection->forceFill(['active_credential_version_id' => $credential->id])->save();

        $account = EmailAccount::query()->create([
            'address' => 'mailbox@example.test',
            'from_name' => 'Mailbox',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => true,
            'delete_policy' => 'local_only',
            'provider_integration_id' => $providerId,
            'provider_credential_source' => 'integration',
            'provider_binding_version' => 1,
            'last_test_result' => 'OK',
            'last_test_at' => now(),
        ]);

        return [$account, $connection->refresh(), $credential];
    }

    private function runCutover(): void
    {
        $migration = require database_path(
            'migrations/2026_09_01_110000_promote_email_accounts_to_account_owned_credentials.php',
        );
        $migration->up();
    }
}
