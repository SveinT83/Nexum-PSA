<?php

namespace App\Modules\Integration\Support;

final readonly class EmailProviderEndpoint
{
    private \SensitiveParameterValue $host;

    public function __construct(
        private string $protocol,
        #[\SensitiveParameter] string $host,
        private int $port,
        private string $transport,
        private string $policyIdentifier,
    ) {
        $this->host = new \SensitiveParameterValue($host);
    }

    public function protocol(): string
    {
        return $this->protocol;
    }

    public function host(): string
    {
        return (string) $this->host->getValue();
    }

    public function port(): int
    {
        return $this->port;
    }

    public function transport(): string
    {
        return $this->transport;
    }

    public function policyIdentifier(): string
    {
        return $this->policyIdentifier;
    }

    /** @return array{protocol: string, port: int, transport: string, policy_identifier: string} */
    public function toSafeArray(): array
    {
        return [
            'protocol' => $this->protocol,
            'port' => $this->port,
            'transport' => $this->transport,
            'policy_identifier' => $this->policyIdentifier,
        ];
    }

    /** @return array{protocol: string, port: int, transport: string, policy_identifier: string} */
    public function __debugInfo(): array
    {
        return $this->toSafeArray();
    }
}
