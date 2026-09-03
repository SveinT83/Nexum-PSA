<?php

use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Services\EmailProviderCredentialCipher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('email_accounts')
                ->orderBy('id')
                ->get()
                ->each(function (object $account): void {
                    $source = (string) ($account->provider_credential_source ?: 'legacy');

                    if ($source === 'integration') {
                        $this->promoteProviderAccount($account);

                        return;
                    }

                    if (! in_array($source, ['account', 'legacy'], true)) {
                        throw new RuntimeException("Email account {$account->id} has an unsupported credential source.");
                    }

                    $this->assertAccountCredentials($account);

                    DB::table('email_accounts')->where('id', $account->id)->update([
                        'provider_integration_id' => null,
                        'provider_credential_source' => 'account',
                        'provider_binding_version' => max(1, (int) $account->provider_binding_version + ($source === 'account' ? 0 : 1)),
                        'provider_bound_at' => $account->provider_bound_at ?: now(),
                        'provider_runtime_paused_at' => null,
                        'provider_runtime_drained_at' => null,
                        'provider_runtime_paused_by' => null,
                        'provider_runtime_pause_reason_code' => null,
                        'updated_at' => now(),
                    ]);
                });

            if (DB::table('email_accounts')
                ->where(function ($query): void {
                    $query->where('provider_credential_source', '!=', 'account')
                        ->orWhereNotNull('provider_integration_id');
                })->exists()) {
                throw new RuntimeException('Not every Email account was promoted to account-owned credentials.');
            }

            // Destroy the obsolete duplicate secret store only after every
            // account has been promoted successfully in this transaction.
            DB::table('integration_email_provider_connections')->update([
                'status' => 'revoked',
                'active_credential_version_id' => null,
                'verification_claim_token' => null,
                'verification_claim_configuration_version' => null,
                'verification_claim_credential_version' => null,
                'verification_claim_expires_at' => null,
                'updated_at' => now(),
            ]);

            DB::table('integration_email_provider_credential_versions')->update([
                'state' => EmailProviderCredentialVersion::STATE_DESTROYED,
                'imap_username_encrypted' => null,
                'imap_secret_encrypted' => null,
                'smtp_username_encrypted' => null,
                'smtp_secret_encrypted' => null,
                'destroyed_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('integrations')
                ->where('type', 'email_provider')
                ->update([
                    'status' => 'disabled',
                    'secrets' => null,
                    'is_healthy' => false,
                    'updated_at' => now(),
                ]);
        }, 3);

        // MariaDB can change this default in place. SQLite would rebuild the
        // table and invalidate the durable authority triggers used by tests.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE email_accounts MODIFY provider_credential_source VARCHAR(24) NOT NULL DEFAULT 'account'",
            );
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The account-owned Email credential cutover destroys the duplicate provider ciphertext and cannot be reversed.',
        );
    }

    private function promoteProviderAccount(object $account): void
    {
        $connection = EmailProviderConnection::query()
            ->whereKey($account->provider_integration_id)
            ->lockForUpdate()
            ->first();

        if (! $connection
            || $connection->status !== 'active'
            || ! $connection->active_credential_version_id) {
            throw new RuntimeException("Email account {$account->id} has no active provider configuration to promote.");
        }

        $credential = EmailProviderCredentialVersion::query()
            ->whereKey($connection->active_credential_version_id)
            ->where('provider_integration_id', $connection->getKey())
            ->lockForUpdate()
            ->first();

        if (! $credential
            || $credential->state !== EmailProviderCredentialVersion::STATE_ACTIVE
            || $credential->verification_code !== 'verified'
            || (int) $credential->version !== (int) $connection->verified_credential_version
            || (int) $credential->verified_configuration_version !== (int) $connection->configuration_version
            || (int) $connection->verified_configuration_version !== (int) $connection->configuration_version) {
            throw new RuntimeException("Email account {$account->id} provider credentials are not exactly verified.");
        }

        $credentials = app(EmailProviderCredentialCipher::class)->decrypt($credential);

        foreach (['imap_username', 'imap_secret', 'smtp_username', 'smtp_secret'] as $key) {
            if (blank($credentials[$key] ?? null)) {
                throw new RuntimeException("Email account {$account->id} provider credentials are incomplete.");
            }
        }

        foreach (['imap_host', 'imap_port', 'imap_transport', 'smtp_host', 'smtp_port', 'smtp_transport'] as $key) {
            if (blank($connection->getAttribute($key))) {
                throw new RuntimeException("Email account {$account->id} provider endpoints are incomplete.");
            }
        }

        DB::table('email_accounts')->where('id', $account->id)->update([
            'imap_host' => $connection->imap_host,
            'imap_port' => $connection->imap_port,
            'imap_encryption' => $connection->imap_transport,
            'imap_username' => $credentials['imap_username'],
            'imap_secret' => Crypt::encryptString($credentials['imap_secret']),
            'imap_auth_type' => $connection->imap_auth_type ?: 'password',
            'smtp_host' => $connection->smtp_host,
            'smtp_port' => $connection->smtp_port,
            'smtp_encryption' => $connection->smtp_transport,
            'smtp_username' => $credentials['smtp_username'],
            'smtp_secret' => Crypt::encryptString($credentials['smtp_secret']),
            'smtp_auth_type' => $connection->smtp_auth_type ?: 'password',
            'provider_integration_id' => null,
            'provider_credential_source' => 'account',
            'provider_binding_version' => max(1, (int) $account->provider_binding_version + 1),
            'provider_bound_at' => now(),
            'provider_runtime_paused_at' => null,
            'provider_runtime_drained_at' => null,
            'provider_runtime_paused_by' => null,
            'provider_runtime_pause_reason_code' => null,
            'updated_at' => now(),
        ]);

        unset($credentials);
    }

    private function assertAccountCredentials(object $account): void
    {
        foreach (['imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_secret', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_secret'] as $key) {
            if (blank($account->{$key} ?? null)) {
                throw new RuntimeException("Email account {$account->id} has incomplete account-owned credentials.");
            }
        }

        try {
            Crypt::decryptString((string) $account->imap_secret);
            Crypt::decryptString((string) $account->smtp_secret);
        } catch (Throwable) {
            throw new RuntimeException("Email account {$account->id} account-owned credentials cannot be decrypted.");
        }
    }
};
