<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;

final class EmailProviderAuthenticationPolicy
{
    /**
     * Password authentication is the only Stage 6 driver. OAuth needs a
     * provider-specific token lifecycle and must not be stored as a password.
     */
    public function normalize(string $protocol, string $authType): string
    {
        $protocol = strtolower(trim($protocol));
        $authType = strtolower(trim($authType));

        if (! in_array($protocol, ['imap', 'smtp'], true) || $authType !== 'password') {
            throw new EmailProviderSecurityException('authentication_type_not_supported');
        }

        return $authType;
    }

    /**
     * Legacy Email used provider mechanism labels for password credentials.
     * Canonicalize only the known password mechanisms; OAuth and unknown
     * labels remain unsupported instead of being copied into Integration.
     */
    public function normalizeLegacy(string $protocol, string $authType): string
    {
        $protocol = strtolower(trim($protocol));
        $authType = strtolower(trim($authType));

        if (! in_array($protocol, ['imap', 'smtp'], true)
            || ! in_array($authType, ['password', 'plain', 'login'], true)) {
            throw new EmailProviderSecurityException('authentication_type_not_supported');
        }

        return 'password';
    }
}
