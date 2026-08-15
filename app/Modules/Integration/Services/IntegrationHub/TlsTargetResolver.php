<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use Symfony\Component\HttpFoundation\IpUtils;

class TlsTargetResolver
{
    /** @return list<string> */
    public function resolve(string $hostname): array
    {
        set_error_handler(static fn (): bool => true);
        try {
            $records = dns_get_record($hostname, DNS_A | DNS_AAAA);
        } finally {
            restore_error_handler();
        }
        if (! is_array($records)) {
            throw new IntegrationHubDeniedException('tls_dns_unavailable', 'TLS target DNS is unavailable.', 503, 'unavailable', true);
        }

        $addresses = collect($records)->map(fn (array $record): ?string => isset($record['ip'])
            ? (string) $record['ip']
            : (isset($record['ipv6']) ? (string) $record['ipv6'] : null)
        )->filter()->unique()->values();
        if ($addresses->isEmpty()) {
            throw new IntegrationHubDeniedException('tls_dns_unavailable', 'TLS target DNS is unavailable.', 503, 'unavailable', true);
        }
        if ($addresses->count() > 16) {
            throw new IntegrationHubDeniedException('tls_dns_response_too_large', 'TLS target DNS is ambiguous.', 409, 'unknown');
        }
        if ($addresses->contains(fn (string $address): bool => ! $this->isPublicAddress($address))) {
            throw new IntegrationHubDeniedException('tls_target_not_public', 'TLS target is outside the public network boundary.');
        }

        return $addresses->sort()->take(4)->values()->all();
    }

    public function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return ! IpUtils::checkIp($address, [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
            '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
            '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32',
            'fc00::/7', 'fe80::/10', 'ff00::/8',
        ]);
    }
}
