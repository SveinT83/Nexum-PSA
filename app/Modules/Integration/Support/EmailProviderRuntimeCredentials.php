<?php

namespace App\Modules\Integration\Support;

use LogicException;

/**
 * Short-lived provider material. It cannot be serialized into a queue/session
 * and exposes only a redacted representation to debuggers and logs.
 */
final readonly class EmailProviderRuntimeCredentials
{
    private \SensitiveParameterValue $imapUsername;

    private \SensitiveParameterValue $imapSecret;

    private \SensitiveParameterValue $smtpUsername;

    private \SensitiveParameterValue $smtpSecret;

    public function __construct(
        private string $providerIntegrationId,
        private int $configurationVersion,
        private int $credentialVersion,
        private EmailProviderResolvedEndpoint $imapEndpoint,
        private EmailProviderResolvedEndpoint $smtpEndpoint,
        #[\SensitiveParameter] string $imapUsername,
        #[\SensitiveParameter] string $imapSecret,
        #[\SensitiveParameter] string $smtpUsername,
        #[\SensitiveParameter] string $smtpSecret,
        private string $imapAuthType,
        private string $smtpAuthType,
    ) {
        $this->imapUsername = new \SensitiveParameterValue($imapUsername);
        $this->imapSecret = new \SensitiveParameterValue($imapSecret);
        $this->smtpUsername = new \SensitiveParameterValue($smtpUsername);
        $this->smtpSecret = new \SensitiveParameterValue($smtpSecret);
    }

    public function providerIntegrationId(): string
    {
        return $this->providerIntegrationId;
    }

    public function configurationVersion(): int
    {
        return $this->configurationVersion;
    }

    public function credentialVersion(): int
    {
        return $this->credentialVersion;
    }

    public function imapEndpoint(): EmailProviderResolvedEndpoint
    {
        return $this->imapEndpoint;
    }

    public function smtpEndpoint(): EmailProviderResolvedEndpoint
    {
        return $this->smtpEndpoint;
    }

    public function imapAuthType(): string
    {
        return $this->imapAuthType;
    }

    public function smtpAuthType(): string
    {
        return $this->smtpAuthType;
    }

    public function imapUsername(): string
    {
        return (string) $this->imapUsername->getValue();
    }

    public function imapSecret(): string
    {
        return (string) $this->imapSecret->getValue();
    }

    public function smtpUsername(): string
    {
        return (string) $this->smtpUsername->getValue();
    }

    public function smtpSecret(): string
    {
        return (string) $this->smtpSecret->getValue();
    }

    /** @return array<string, mixed> */
    public function toSafeArray(): array
    {
        return [
            'provider_integration_id' => $this->providerIntegrationId,
            'configuration_version' => $this->configurationVersion,
            'credential_version' => $this->credentialVersion,
            'imap' => $this->imapEndpoint->toSafeArray(),
            'smtp' => $this->smtpEndpoint->toSafeArray(),
            'credentials' => '[REDACTED]',
        ];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->toSafeArray();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Email provider runtime credentials may not be serialized.');
    }
}
