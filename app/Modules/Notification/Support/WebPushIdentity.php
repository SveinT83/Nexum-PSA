<?php

namespace App\Modules\Notification\Support;

/** Verify a stable P-256 identity locally, without publishing, logging or sending its private value. */
final class WebPushIdentity
{
    public static function validSubject(mixed $subject): bool
    {
        if (! is_string($subject) || strlen($subject) > 512 || trim($subject) !== $subject || $subject === '') {
            return false;
        }
        if (str_starts_with($subject, 'mailto:')) {
            return filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false;
        }

        return str_starts_with($subject, 'https://') && filter_var($subject, FILTER_VALIDATE_URL) !== false
            && parse_url($subject, PHP_URL_USER) === null && parse_url($subject, PHP_URL_PASS) === null;
    }

    public static function valid(#[\SensitiveParameter] array $identity): bool
    {
        if (! function_exists('openssl_pkey_get_private') || ! function_exists('openssl_error_string')) {
            return false;
        }
        if (! self::validSubject($identity['subject'] ?? null)) {
            return false;
        }
        $public = self::decode($identity['public_key'] ?? null);
        $private = self::decode($identity['private_key'] ?? null);
        if ($public === null || $private === null || strlen($public) !== 65 || strlen($private) !== 32 || $public[0] !== "\x04") {
            return false;
        }
        try {
            // RFC 5915 ECPrivateKey with named P-256 parameters and no supplied public point.
            // OpenSSL derives the public coordinates from d, allowing a genuine key-pair comparison.
            $der = "\x30\x31\x02\x01\x01\x04\x20".$private."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
            $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
            $key = @openssl_pkey_get_private($pem);
            if ($key === false) {
                return false;
            }
            $details = openssl_pkey_get_details($key);
            if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1'
                || ! is_string($details['ec']['x'] ?? null) || ! is_string($details['ec']['y'] ?? null)) {
                return false;
            }

            return hash_equals($public, "\x04".str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT));
        } catch (\Throwable) {
            return false;
        } finally {
            while (openssl_error_string() !== false) { /* Discard crypto diagnostics; never copy key-bearing details. */
            }
        }
    }

    public static function matchesPublicKey(mixed $provided, mixed $expected): bool
    {
        $a = self::decode($provided);
        $b = self::decode($expected);

        return $a !== null && $b !== null && strlen($a) === 65 && strlen($b) === 65
            && $a[0] === "\x04" && hash_equals($b, $a);
    }

    private static function decode(#[\SensitiveParameter] mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 160 || ! preg_match('/\A[A-Za-z0-9_+\/-]+={0,2}\z/D', $value)) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
