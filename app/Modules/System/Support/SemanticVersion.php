<?php

namespace App\Modules\System\Support;

/**
 * Normalizes the strict Semantic Version values used by Nexum releases.
 */
final class SemanticVersion
{
    private const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
        .'(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?'
        .'(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/';

    public static function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = trim($value);

        if ($candidate !== '' && in_array($candidate[0], ['v', 'V'], true)) {
            $candidate = substr($candidate, 1);
        }

        if ($candidate === '' || preg_match(self::PATTERN, $candidate) !== 1) {
            return null;
        }

        return $candidate;
    }

    public static function isNewer(?string $candidate, ?string $installed): ?bool
    {
        $candidate = self::normalize($candidate);
        $installed = self::normalize($installed);

        if ($candidate === null || $installed === null) {
            return null;
        }

        return version_compare($candidate, $installed, '>');
    }

    public static function isPrerelease(string $version): bool
    {
        $normalized = self::normalize($version);

        return $normalized !== null && str_contains($normalized, '-');
    }
}
