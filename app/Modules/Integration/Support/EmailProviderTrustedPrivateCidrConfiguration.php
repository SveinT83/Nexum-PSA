<?php

namespace App\Modules\Integration\Support;

/**
 * Builds narrowly approved private-provider CIDR configuration.
 *
 * Installation environment values are untrusted input. Invalid values omit
 * the named group entirely so they cannot appear in the provider UI or widen
 * the endpoint policy through an accidental fallback.
 */
final class EmailProviderTrustedPrivateCidrConfiguration
{
    /**
     * @return array<string, list<string>>
     */
    public static function exactRfc1918Ipv4Host(
        string $name,
        #[\SensitiveParameter] mixed $configuredCidr,
    ): array {
        if (! preg_match('/\A(?<address>[^\/\s]+)\/32\z/D', is_string($configuredCidr) ? $configuredCidr : '', $matches)) {
            return [];
        }

        $address = filter_var($matches['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if (! is_string($address) || ! self::isRfc1918($address)) {
            return [];
        }

        return [$name => [$address.'/32']];
    }

    private static function isRfc1918(string $address): bool
    {
        $bytes = inet_pton($address);

        if ($bytes === false || strlen($bytes) !== 4) {
            return false;
        }

        $first = ord($bytes[0]);
        $second = ord($bytes[1]);

        return $first === 10
            || ($first === 172 && $second >= 16 && $second <= 31)
            || ($first === 192 && $second === 168);
    }
}
