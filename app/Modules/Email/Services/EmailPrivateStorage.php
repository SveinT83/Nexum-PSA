<?php

namespace App\Modules\Email\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Writes private Email payloads with a cross-runtime group permission contract.
 *
 * PHP-FPM and queue workers intentionally run as different operating-system
 * users. Flysystem directory modes are filtered by each process umask, so this
 * writer explicitly normalizes directories created by the current process and
 * verifies the final file before returning success.
 */
final class EmailPrivateStorage
{
    /**
     * Paths remain on the established private local disk so existing database
     * references and retention readers stay compatible. Only writes routed
     * through this Email-owned service receive the shared group policy.
     */
    public const DISK = 'local';

    public function put(string $path, string $contents): bool
    {
        $path = $this->emailPath($path);

        try {
            $disk = Storage::disk(self::DISK);
            $directory = trim(str_replace('\\', '/', dirname($path)), './');

            if (! $this->ensureDirectory($disk, $directory)) {
                $this->logWriteFailure($path, 'directory_not_writable');

                return false;
            }

            $existedBefore = $disk->exists($path);
            $stored = $disk->put($path, $contents, ['visibility' => 'private']);

            if (! $stored || ! $disk->exists($path)) {
                if (! $existedBefore && $disk->exists($path)) {
                    $disk->delete($path);
                }

                $this->logWriteFailure($path, 'write_not_verified');

                return false;
            }

            if (! $this->normalizeOwnedPath($disk, $path, 0660, false)) {
                if (! $existedBefore) {
                    $disk->delete($path);
                }

                $this->logWriteFailure($path, 'file_permissions_not_shared');

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            $this->logWriteFailure($path, 'storage_exception', $exception);

            return false;
        }
    }

    private function ensureDirectory(FilesystemAdapter $disk, string $directory): bool
    {
        if ($directory === '') {
            return false;
        }

        $current = '';

        foreach (explode('/', $directory) as $segment) {
            if ($segment === '') {
                continue;
            }

            $current = $current === '' ? $segment : $current.'/'.$segment;

            if (! $disk->directoryExists($current)
                && ! $disk->makeDirectory($current, ['visibility' => 'private'])) {
                return false;
            }

            if (! $this->normalizeOwnedPath($disk, $current, 02770, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeOwnedPath(
        FilesystemAdapter $disk,
        string $path,
        int $mode,
        bool $directory,
    ): bool {
        $absolutePath = $disk->path($path);

        if (! file_exists($absolutePath)) {
            return false;
        }

        $owner = @fileowner($absolutePath);
        $effectiveUser = function_exists('posix_geteuid') ? posix_geteuid() : null;

        // Only the owner (or root) may chmod. Existing paths created by the
        // companion runtime are valid when the current process can use them;
        // the one-time operations normalization covers legacy restrictive rows.
        if ($effectiveUser === 0 || ($effectiveUser !== null && $owner === $effectiveUser)) {
            if (! @chmod($absolutePath, $mode)) {
                return false;
            }

            clearstatcache(true, $absolutePath);
        }

        if ($directory) {
            return is_dir($absolutePath) && is_readable($absolutePath) && is_writable($absolutePath);
        }

        $permissions = @fileperms($absolutePath);

        return is_file($absolutePath)
            && is_readable($absolutePath)
            && $permissions !== false
            && (($permissions & 0060) === 0060);
    }

    private function emailPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), '/');

        if ($path === 'email' || ! str_starts_with($path, 'email/') || str_contains($path, '../')) {
            throw new InvalidArgumentException('Email private storage accepts only normalized email/* paths.');
        }

        return $path;
    }

    private function logWriteFailure(string $path, string $reason, ?Throwable $exception = null): void
    {
        $segments = explode('/', $path);

        Log::warning('Email private payload could not be stored.', [
            'storage_scope' => implode('/', array_slice($segments, 0, 2)),
            'reason' => $reason,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
