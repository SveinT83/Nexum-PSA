<?php

namespace App\Modules\Email\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Verifies that the current PHP runtime can create both historical payload
 * classes before a provider message is fetched.
 *
 * This is intentionally independent of attachment-repair schema readiness:
 * historical import needs both raw RFC 822 and attachment roots, and checks
 * them again at every queue batch boundary.
 */
class EmailHistoricalImportStorageReadiness
{
    public const FAILURE_CODE = 'HISTORICAL_IMPORT_PRIVATE_STORAGE_UNAVAILABLE';

    /** @return array{safe: bool, reason_code: string} */
    public function check(): array
    {
        try {
            $disk = Storage::disk(EmailPrivateStorage::DISK);
            $root = realpath($disk->path(''));

            if ($root === false) {
                return ['safe' => false, 'reason_code' => 'private_root_missing'];
            }

            foreach (['email/raw', 'email/attachments'] as $relativePath) {
                if (! $this->pathCanBeCreatedOrWritten($disk->path($relativePath), $root)) {
                    return [
                        'safe' => false,
                        'reason_code' => str_replace('/', '_', $relativePath).'_not_writable',
                    ];
                }
            }

            return ['safe' => true, 'reason_code' => 'private_payload_roots_writable'];
        } catch (Throwable $exception) {
            Log::warning('Historical Email import storage readiness check failed.', [
                'reason' => 'private_storage_check_failed',
                'exception' => $exception::class,
            ]);

            return ['safe' => false, 'reason_code' => 'private_storage_check_failed'];
        }
    }

    private function pathCanBeCreatedOrWritten(string $candidate, string $root): bool
    {
        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);
        if (! str_starts_with($candidate, $normalizedRoot.DIRECTORY_SEPARATOR)) {
            return false;
        }

        if (file_exists($candidate) && ! is_dir($candidate)) {
            return false;
        }

        while (! file_exists($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate
                || ($parent !== $normalizedRoot
                    && ! str_starts_with($parent, $normalizedRoot.DIRECTORY_SEPARATOR))) {
                return false;
            }

            $candidate = $parent;
        }

        return is_dir($candidate)
            && is_readable($candidate)
            && is_writable($candidate)
            && is_executable($candidate);
    }
}
