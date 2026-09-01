<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderAuthenticationPolicy;
use App\Modules\Integration\Services\EmailProviderEndpointAuthorizer;
use App\Modules\Integration\Services\EmailProviderEndpointPolicy;
use App\Modules\Integration\Services\EmailProviderRuntimeFactory;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use Illuminate\Support\Facades\Crypt;

final class EmailAccountProviderRuntimeResolver
{
    public function __construct(
        private readonly EmailProviderRuntimeFactory $integrationRuntime,
        private readonly EmailProviderEndpointPolicy $endpoints,
        private readonly EmailProviderEndpointAuthorizer $authorizer,
        private readonly EmailProviderAuthenticationPolicy $authentication,
    ) {}

    /**
     * Re-read the binding at execution time. Passing an expected version is
     * mandatory for work reserved or dispatched before this call.
     */
    public function resolve(
        #[\SensitiveParameter] EmailAccount $account,
        ?int $expectedBindingVersion = null,
    ): EmailProviderRuntimeCredentials {
        $account = EmailAccount::query()->findOrFail($account->id);
        $this->assertUsableAccount($account, $expectedBindingVersion);

        if ($this->source($account) === 'integration') {
            if (blank($account->provider_integration_id)) {
                throw new EmailProviderSecurityException('provider_binding_missing');
            }

            return $this->integrationRuntime->active((string) $account->provider_integration_id);
        }

        if (! in_array($this->source($account), ['account', 'legacy'], true)) {
            throw new EmailProviderSecurityException('provider_source_invalid');
        }

        return $this->legacyRuntime($account);
    }

    /**
     * Resolve saved account-owned credentials for an administrator-initiated
     * connection check. The account may remain inactive until both probes pass.
     */
    public function resolveForConfigurationTest(
        #[\SensitiveParameter] EmailAccount $account,
        int $expectedBindingVersion,
    ): EmailProviderRuntimeCredentials {
        $account = EmailAccount::query()->findOrFail($account->id);

        if ($this->bindingVersion($account) !== $expectedBindingVersion) {
            throw new EmailProviderSecurityException('provider_binding_stale');
        }

        if ($this->source($account) === 'integration') {
            if (blank($account->provider_integration_id)) {
                throw new EmailProviderSecurityException('provider_binding_missing');
            }

            return $this->integrationRuntime->active((string) $account->provider_integration_id);
        }

        if (! in_array($this->source($account), ['account', 'legacy'], true)) {
            throw new EmailProviderSecurityException('provider_source_invalid');
        }

        return $this->legacyRuntime($account);
    }

    /**
     * Database-only readiness for lists and authorization gates. It performs
     * no DNS lookup and no provider call.
     */
    public function databaseReady(#[\SensitiveParameter] EmailAccount $account): bool
    {
        $account = EmailAccount::query()->find($account->id);

        if (! $account || ! $account->is_active || $account->provider_runtime_paused_at) {
            return false;
        }

        try {
            $this->bindingVersion($account);
        } catch (EmailProviderSecurityException) {
            return false;
        }

        if ($this->source($account) === 'integration') {
            return filled($account->provider_integration_id)
                && $this->integrationRuntime->databaseReady((string) $account->provider_integration_id);
        }

        if (! in_array($this->source($account), ['account', 'legacy'], true)) {
            return false;
        }

        try {
            $this->endpoints->normalize(
                'imap',
                (string) $account->imap_host,
                (int) $account->imap_port,
                (string) $account->imap_encryption,
            );
            $this->endpoints->normalize(
                'smtp',
                (string) $account->smtp_host,
                (int) $account->smtp_port,
                (string) $account->smtp_encryption,
            );
            $this->authentication->normalizeLegacy('imap', (string) $account->imap_auth_type);
            $this->authentication->normalizeLegacy('smtp', (string) $account->smtp_auth_type);

            return filled($account->imap_username)
                && filled($account->smtp_username)
                && filled($account->imap_secret)
                && filled($account->smtp_secret);
        } catch (\Throwable) {
            return false;
        }
    }

    public function bindingVersion(#[\SensitiveParameter] EmailAccount $account): int
    {
        $version = (int) $account->provider_binding_version;

        if ($version < 1) {
            throw new EmailProviderSecurityException('provider_binding_invalid');
        }

        return $version;
    }

    /**
     * Capture the current database binding for a durable provider-I/O
     * reservation. A stale caller model must never choose the expected value.
     */
    public function captureBindingVersion(#[\SensitiveParameter] EmailAccount $account): int
    {
        $current = EmailAccount::query()->findOrFail($account->id);

        return $this->bindingVersion($current);
    }

    private function assertUsableAccount(#[\SensitiveParameter] EmailAccount $account, ?int $expectedBindingVersion): void
    {
        if (! $account->is_active) {
            throw new EmailProviderSecurityException('email_account_inactive');
        }

        if ($account->provider_runtime_paused_at) {
            throw new EmailProviderSecurityException('provider_runtime_paused');
        }

        if ($expectedBindingVersion !== null
            && $this->bindingVersion($account) !== $expectedBindingVersion) {
            throw new EmailProviderSecurityException('provider_binding_stale');
        }
    }

    private function legacyRuntime(#[\SensitiveParameter] EmailAccount $account): EmailProviderRuntimeCredentials
    {
        try {
            $imapSecret = Crypt::decryptString((string) $account->imap_secret);
            $smtpSecret = Crypt::decryptString((string) $account->smtp_secret);
        } catch (\Throwable) {
            throw new EmailProviderSecurityException('legacy_credential_decryption_failed');
        }

        $imap = $this->endpoints->normalize(
            'imap',
            (string) $account->imap_host,
            (int) $account->imap_port,
            (string) $account->imap_encryption,
        );
        $smtp = $this->endpoints->normalize(
            'smtp',
            (string) $account->smtp_host,
            (int) $account->smtp_port,
            (string) $account->smtp_encryption,
        );
        $imapAuth = $this->authentication->normalizeLegacy('imap', (string) $account->imap_auth_type);
        $smtpAuth = $this->authentication->normalizeLegacy('smtp', (string) $account->smtp_auth_type);
        [$trustMode, $trustedCidrName] = $this->legacyTrust($account);
        $runtime = new EmailProviderRuntimeCredentials(
            providerIntegrationId: 'legacy-account-'.$account->id,
            configurationVersion: $this->bindingVersion($account),
            credentialVersion: 0,
            imapEndpoint: $this->authorizer->authorize($imap, $trustMode, $trustedCidrName),
            smtpEndpoint: $this->authorizer->authorize($smtp, $trustMode, $trustedCidrName),
            imapUsername: (string) $account->imap_username,
            imapSecret: $imapSecret,
            smtpUsername: (string) $account->smtp_username,
            smtpSecret: $smtpSecret,
            imapAuthType: $imapAuth,
            smtpAuthType: $smtpAuth,
        );
        unset($imapSecret, $smtpSecret);

        return $runtime;
    }

    /** @return array{0: string, 1: string|null} */
    private function legacyTrust(#[\SensitiveParameter] EmailAccount $account): array
    {
        $cidrName = config('email_provider_security.legacy_trusted_private_accounts.'.$account->id);

        return filled($cidrName)
            ? ['trusted_private', (string) $cidrName]
            : ['public', null];
    }

    private function source(#[\SensitiveParameter] EmailAccount $account): string
    {
        return (string) ($account->provider_credential_source ?: 'legacy');
    }
}
