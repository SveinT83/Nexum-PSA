<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Support\EmailProviderEndpoint;
use App\Modules\Integration\Support\EmailProviderResolvedEndpoint;

final class EmailProviderEndpointAuthorizer
{
    public function __construct(
        private readonly EmailProviderDnsResolver $dns,
        private readonly EmailProviderIpPolicy $ipPolicy,
    ) {}

    public function authorize(
        #[\SensitiveParameter] EmailProviderEndpoint $endpoint,
        #[\SensitiveParameter] string $trustMode,
        #[\SensitiveParameter] ?string $trustedCidrName,
    ): EmailProviderResolvedEndpoint {
        $addresses = $this->dns->resolve($endpoint->host());
        $authorized = [];

        // Every answer must pass. One denied answer denies the complete set so
        // an attacker cannot hide a private target beside a public address.
        foreach ($addresses as $address) {
            try {
                $authorized[] = $this->ipPolicy->authorize($address, $trustMode, $trustedCidrName);
            } catch (EmailProviderSecurityException) {
                throw new EmailProviderSecurityException('dns_answer_set_denied');
            }
        }

        sort($authorized, SORT_STRING);

        return new EmailProviderResolvedEndpoint($endpoint, $authorized[0]);
    }
}
