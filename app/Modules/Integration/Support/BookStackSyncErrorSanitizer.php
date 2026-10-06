<?php

namespace App\Modules\Integration\Support;

/**
 * Converts provider exceptions into bounded operational messages safe for
 * shared status, UI, API responses, and audit metadata.
 */
class BookStackSyncErrorSanitizer
{
    public function message(\Throwable|string $error): string
    {
        $message = mb_strtolower($error instanceof \Throwable ? $error->getMessage() : $error);

        return match (true) {
            str_contains($message, '429'),
            str_contains($message, 'rate limit'),
            str_contains($message, 'too many attempts') => 'BookStack rate-limited the synchronization request.',
            str_contains($message, '401'),
            str_contains($message, 'unauthenticated'),
            str_contains($message, 'invalid token') => 'BookStack authentication failed.',
            str_contains($message, '403'),
            str_contains($message, 'forbidden') => 'BookStack denied the synchronization request.',
            str_contains($message, '404'),
            str_contains($message, 'not found') => 'The BookStack record could not be found.',
            str_contains($message, 'timeout'),
            str_contains($message, 'timed out') => 'BookStack did not confirm the request before the timeout.',
            str_contains($message, 'connection'),
            str_contains($message, 'could not resolve'),
            str_contains($message, 'unavailable') => 'BookStack is currently unavailable.',
            default => 'BookStack synchronization failed safely. Review the protected application log for diagnostics.',
        };
    }
}
