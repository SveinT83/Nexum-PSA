<?php

namespace App\Modules\Integration\Support;

final readonly class EmailProviderResolvedEndpoint
{
    private \SensitiveParameterValue $pinnedAddress;

    public function __construct(
        private EmailProviderEndpoint $endpoint,
        #[\SensitiveParameter] string $pinnedAddress,
    ) {
        $this->pinnedAddress = new \SensitiveParameterValue($pinnedAddress);
    }

    public function endpoint(): EmailProviderEndpoint
    {
        return $this->endpoint;
    }

    public function pinnedAddress(): string
    {
        return (string) $this->pinnedAddress->getValue();
    }

    /**
     * TLS connects to the pinned address but verifies the original hostname.
     * This safe diagnostic representation intentionally omits both values.
     *
     * @return array{protocol: string, port: int, transport: string, pinned: bool}
     */
    public function toSafeArray(): array
    {
        return [
            'protocol' => $this->endpoint->protocol(),
            'port' => $this->endpoint->port(),
            'transport' => $this->endpoint->transport(),
            'pinned' => true,
        ];
    }

    /** @return array{protocol: string, port: int, transport: string, pinned: bool} */
    public function __debugInfo(): array
    {
        return $this->toSafeArray();
    }
}
