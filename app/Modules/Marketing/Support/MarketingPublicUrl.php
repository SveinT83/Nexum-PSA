<?php

namespace App\Modules\Marketing\Support;

use RuntimeException;

/** Validate and pin every public HTTPS hop, including redirect destinations. */
class MarketingPublicUrl
{
    public function options(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new RuntimeException('WordPress requires a public HTTPS URL without credentials or a custom port.');
        }
        $addresses = $this->addresses($host);
        if (! $addresses) {
            throw new RuntimeException('WordPress hostname could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with($address, '::ffff:') || in_array($address, ['169.254.169.254', '100.100.100.200'], true)
                || (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ip2long($address) >= ip2long('100.64.0.0') && ip2long($address) <= ip2long('100.127.255.255'))) {
                throw new RuntimeException('WordPress destinations must resolve exclusively to public IP addresses.');
            }
        }

        return ['allow_redirects' => false, 'verify' => true,
            'curl' => [CURLOPT_RESOLVE => [$host.':443:'.implode(',', array_map(fn ($ip) => str_contains($ip, ':') ? '['.$ip.']' : $ip, $addresses))]]];
    }

    public function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records))));
    }
}
