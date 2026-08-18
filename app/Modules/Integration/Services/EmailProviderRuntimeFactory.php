<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;

final class EmailProviderRuntimeFactory
{
    public function __construct(
        private readonly EmailProviderEndpointPolicy $endpointPolicy,
        private readonly EmailProviderAuthenticationPolicy $authentication,
        private readonly EmailProviderEndpointAuthorizer $endpointAuthorizer,
        private readonly EmailProviderCredentialCipher $cipher,
    ) {}

    public function active(string $providerIntegrationId): EmailProviderRuntimeCredentials
    {
        $connection = EmailProviderConnection::query()
            ->with('activeCredentialVersion')
            ->find($providerIntegrationId);

        if (! $connection || $connection->status !== 'active') {
            throw new EmailProviderSecurityException('provider_not_active');
        }

        $version = $connection->activeCredentialVersion;

        if (! $this->isDatabaseReady($connection, $version)) {
            throw new EmailProviderSecurityException('provider_verification_stale');
        }

        return $this->exact($connection, $version);
    }

    public function exact(
        #[\SensitiveParameter] EmailProviderConnection $connection,
        #[\SensitiveParameter] EmailProviderCredentialVersion $version,
    ): EmailProviderRuntimeCredentials {
        if ($version->provider_integration_id !== $connection->getKey()
            || ! in_array($version->state, [
                EmailProviderCredentialVersion::STATE_STAGED,
                EmailProviderCredentialVersion::STATE_ACTIVE,
            ], true)) {
            throw new EmailProviderSecurityException('credential_version_invalid');
        }

        $imap = $this->endpointPolicy->normalize(
            'imap',
            $connection->imap_host,
            (int) $connection->imap_port,
            $connection->imap_transport,
        );
        $smtp = $this->endpointPolicy->normalize(
            'smtp',
            $connection->smtp_host,
            (int) $connection->smtp_port,
            $connection->smtp_transport,
        );

        if (! hash_equals((string) $connection->imap_endpoint_policy_id, $imap->policyIdentifier())
            || ! hash_equals((string) $connection->smtp_endpoint_policy_id, $smtp->policyIdentifier())) {
            throw new EmailProviderSecurityException('endpoint_policy_snapshot_stale');
        }
        $imapAuthType = $this->authentication->normalize('imap', (string) $connection->imap_auth_type);
        $smtpAuthType = $this->authentication->normalize('smtp', (string) $connection->smtp_auth_type);
        $material = $this->cipher->decrypt($version);

        return new EmailProviderRuntimeCredentials(
            providerIntegrationId: $connection->getKey(),
            configurationVersion: (int) $connection->configuration_version,
            credentialVersion: (int) $version->version,
            imapEndpoint: $this->endpointAuthorizer->authorize(
                $imap,
                $connection->trust_mode,
                $connection->trusted_cidr_name,
            ),
            smtpEndpoint: $this->endpointAuthorizer->authorize(
                $smtp,
                $connection->trust_mode,
                $connection->trusted_cidr_name,
            ),
            imapUsername: $material['imap_username'],
            imapSecret: $material['imap_secret'],
            smtpUsername: $material['smtp_username'],
            smtpSecret: $material['smtp_secret'],
            imapAuthType: $imapAuthType,
            smtpAuthType: $smtpAuthType,
        );
    }

    public function databaseReady(string $providerIntegrationId): bool
    {
        $connection = EmailProviderConnection::query()
            ->with('activeCredentialVersion')
            ->find($providerIntegrationId);

        return $connection !== null
            && $this->isDatabaseReady($connection, $connection->activeCredentialVersion);
    }

    public function databaseReadySnapshot(
        #[\SensitiveParameter] EmailProviderConnection $connection,
        #[\SensitiveParameter] ?EmailProviderCredentialVersion $version,
    ): bool {
        return $this->isDatabaseReady($connection, $version);
    }

    private function isDatabaseReady(
        EmailProviderConnection $connection,
        ?EmailProviderCredentialVersion $version,
    ): bool {
        return $connection->status === 'active'
            && $version !== null
            && (int) $connection->active_credential_version_id === (int) $version->id
            && $version->provider_integration_id === $connection->getKey()
            && $version->state === EmailProviderCredentialVersion::STATE_ACTIVE
            && (int) $connection->verified_configuration_version === (int) $connection->configuration_version
            && (int) $connection->verified_credential_version === (int) $version->version
            && (int) $version->verified_configuration_version === (int) $connection->configuration_version
            && $version->verified_at !== null
            && $version->hasCiphertext()
            && $this->configurationMetadataReady($connection);
    }

    private function configurationMetadataReady(
        #[\SensitiveParameter] EmailProviderConnection $connection,
    ): bool {
        try {
            $imap = $this->endpointPolicy->normalize(
                'imap',
                (string) $connection->imap_host,
                (int) $connection->imap_port,
                (string) $connection->imap_transport,
            );
            $smtp = $this->endpointPolicy->normalize(
                'smtp',
                (string) $connection->smtp_host,
                (int) $connection->smtp_port,
                (string) $connection->smtp_transport,
            );
            $this->authentication->normalize('imap', (string) $connection->imap_auth_type);
            $this->authentication->normalize('smtp', (string) $connection->smtp_auth_type);

            return hash_equals((string) $connection->imap_endpoint_policy_id, $imap->policyIdentifier())
                && hash_equals((string) $connection->smtp_endpoint_policy_id, $smtp->policyIdentifier());
        } catch (\Throwable) {
            return false;
        }
    }
}
