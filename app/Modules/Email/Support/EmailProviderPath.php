<?php

namespace App\Modules\Email\Support;

use InvalidArgumentException;

/**
 * Validate one durable IMAP mailbox path without case-folding its identity.
 *
 * RFC IMAP gives the root INBOX a special case-insensitive name. That rule does
 * not extend to descendants or any other provider folder, so the returned path
 * preserves every byte except an exact five-byte root INBOX spelling.
 */
final class EmailProviderPath
{
    public const MAX_CHARACTERS = 512;

    public const MAX_BYTES = 2048;

    public static function normalize(#[\SensitiveParameter] string $path): string
    {
        if (trim($path) === ''
            || str_contains($path, "\0")
            || ! mb_check_encoding($path, 'UTF-8')
            || mb_strlen($path, 'UTF-8') > self::MAX_CHARACTERS
            || strlen($path) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Provider folder path is invalid or too long.');
        }

        return strlen($path) === 5 && strcasecmp($path, 'INBOX') === 0
            ? 'INBOX'
            : $path;
    }

    public static function normalizeNullable(
        #[\SensitiveParameter] ?string $path,
    ): ?string {
        return $path === null ? null : self::normalize($path);
    }
}
