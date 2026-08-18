<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;

final class EmailProviderIpPolicy
{
    /**
     * IANA special-purpose registries snapshot dated 2025-10-09, plus the
     * protocol-reserved multicast, deprecated site-local, and former 6bone
     * ranges that must never become provider destinations. RFC1918 and ULA
     * are intentionally kept in PRIVATE_RANGES so an explicitly named,
     * Superuser-approved installation CIDR can authorize only those ranges.
     *
     * @see https://www.iana.org/assignments/iana-ipv4-special-registry/
     * @see https://www.iana.org/assignments/iana-ipv6-special-registry/
     *
     * @var list<string>
     */
    private const ALWAYS_DENY_IANA_2025_10_09 = [
        '0.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.31.196.0/24',
        '192.52.193.0/24',
        '192.88.99.0/24',
        '192.175.48.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        '::/96',
        '::ffff:0:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '100:0:0:1::/64',
        '2001::/23',
        '2001:2::/48',
        '2001:db8::/32',
        '2002::/16',
        '2620:4f:8000::/48',
        '3ffe::/16',
        '3fff::/20',
        '5f00::/16',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /** @var list<string> */
    private const PRIVATE_RANGES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ];

    public function authorize(#[\SensitiveParameter] string $address, string $trustMode, #[\SensitiveParameter] ?string $trustedCidrName): string
    {
        $address = $this->normalize($address);

        if ($this->inAny($address, self::ALWAYS_DENY_IANA_2025_10_09)) {
            throw new EmailProviderSecurityException('address_always_denied');
        }

        if ($trustMode === 'public') {
            $bytes = inet_pton($address);
            $isOrdinaryGlobalIpv6 = strlen((string) $bytes) !== 16
                || $this->contains('2000::/3', $address);

            if (! $isOrdinaryGlobalIpv6
                || $this->inAny($address, self::PRIVATE_RANGES)
                || filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) === false) {
                throw new EmailProviderSecurityException('address_not_public');
            }

            return $address;
        }

        if ($trustMode !== 'trusted_private' || blank($trustedCidrName)) {
            throw new EmailProviderSecurityException('trust_mode_invalid');
        }

        if (! $this->inAny($address, self::PRIVATE_RANGES)) {
            throw new EmailProviderSecurityException('trusted_address_not_private');
        }

        $configured = config('email_provider_security.trusted_private_cidrs', []);
        $cidrs = is_array($configured) ? ($configured[$trustedCidrName] ?? null) : null;
        $cidrs = is_string($cidrs) ? [$cidrs] : $cidrs;

        if (! is_array($cidrs) || $cidrs === [] || ! $this->inAny($address, $cidrs)) {
            throw new EmailProviderSecurityException('trusted_cidr_mismatch');
        }

        return $address;
    }

    public function normalize(#[\SensitiveParameter] string $address): string
    {
        $bytes = @inet_pton($address);

        if ($bytes === false) {
            throw new EmailProviderSecurityException('address_invalid');
        }

        // IPv4-mapped IPv6 is policy-equivalent to its embedded IPv4 value.
        // Normalizing it first prevents a public mapped address from being
        // blanket-denied and prevents a private/loopback address from hiding
        // behind IPv6 syntax.
        if (strlen($bytes) === 16
            && substr($bytes, 0, 10) === str_repeat("\0", 10)
            && substr($bytes, 10, 2) === "\xff\xff") {
            return (string) inet_ntop(substr($bytes, 12, 4));
        }

        return strtolower((string) inet_ntop($bytes));
    }

    /** @param array<int, mixed> $cidrs */
    private function inAny(#[\SensitiveParameter] string $address, #[\SensitiveParameter] array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (is_string($cidr) && $this->contains($cidr, $address)) {
                return true;
            }
        }

        return false;
    }

    private function contains(#[\SensitiveParameter] string $cidr, #[\SensitiveParameter] string $address): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }

        [$network, $prefix] = explode('/', $cidr, 2);
        $networkBytes = @inet_pton($network);
        $addressBytes = @inet_pton($address);

        if ($networkBytes === false || $addressBytes === false || strlen($networkBytes) !== strlen($addressBytes)) {
            return false;
        }

        $maxBits = strlen($networkBytes) * 8;
        $prefix = filter_var($prefix, FILTER_VALIDATE_INT);

        if ($prefix === false || $prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0
            && substr($networkBytes, 0, $wholeBytes) !== substr($addressBytes, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($networkBytes[$wholeBytes]) & $mask) === (ord($addressBytes[$wholeBytes]) & $mask);
    }
}
