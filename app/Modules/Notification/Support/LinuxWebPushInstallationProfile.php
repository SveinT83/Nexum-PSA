<?php

namespace App\Modules\Notification\Support;

use App\Modules\Notification\Contracts\WebPushInstallationProfile;
use Carbon\CarbonImmutable;

/** Root-owned explicit installation/whole-worker attestation; ordinary requests cannot create it. */
final class LinuxWebPushInstallationProfile implements WebPushInstallationProfile
{
    public const CONTRACT = 'notification-managed-webpush-v1';

    public static function installationId(): string
    {
        return hash('sha256', (realpath(base_path()) ?: base_path())."\n".rtrim((string) config('app.url'), '/'));
    }

    public function resolve(): array
    {
        clearstatcache(true);
        $enrollment = (string) config('webpush_setup.enrollment_path');
        $enrolled = $enrollment !== '' && (file_exists($enrollment) || is_link($enrollment));
        $result = ['supported' => false, 'enrolled' => $enrolled, 'reason_code' => 'installation_not_provisioned'];
        if (! config('webpush_setup.managed_allowed', true)) {
            return $result + ['management_forbidden' => true];
        }
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid')) {
            return array_replace($result, ['reason_code' => 'installation_platform_unsupported']);
        }
        $path = (string) config('webpush_setup.capability_path');
        try {
            if (! $enrolled || ! $this->rootArtifact($enrollment) || ! $this->rootArtifact($path)) {
                return $result;
            }
            $marker = json_decode(file_get_contents($enrollment), true, 8, JSON_THROW_ON_ERROR);
            $profile = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
            $id = self::installationId();
            if (! is_array($marker) || ! is_array($profile)
                || ($marker['installation_id'] ?? null) !== $id || ($profile['installation_id'] ?? null) !== $id
                || ($profile['version'] ?? null) !== 1 || ($profile['runtime_contract'] ?? null) !== self::CONTRACT
                || ($profile['secret_format'] ?? null) !== 'laravel-app-key-v1'
                || ! is_string($profile['operator'] ?? null) || trim($profile['operator']) === '' || strlen($profile['operator']) > 100) {
                return array_replace($result, ['reason_code' => 'installation_attestation_mismatch']);
            }
            foreach (isset($profile['expires_at']) ? ['workers_reloaded_at', 'expires_at'] : ['workers_reloaded_at'] as $stamp) {
                $value = $profile[$stamp] ?? null;
                if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value)
                    || (date_parse($value)['warning_count'] ?? 1) !== 0 || (date_parse($value)['error_count'] ?? 1) !== 0) {
                    return array_replace($result, ['reason_code' => 'installation_attestation_invalid_time']);
                }
            }
            $at = CarbonImmutable::now('UTC');
            $reloaded = CarbonImmutable::parse($profile['workers_reloaded_at'] ?? '', 'UTC');
            $expires = isset($profile['expires_at']) ? CarbonImmutable::parse($profile['expires_at'], 'UTC') : null;
            if ($reloaded->isAfter($at) || ($expires && (! $expires->isAfter($reloaded) || ! $expires->isAfter($at) || $expires->isAfter($at->addDays(366))))) {
                return array_replace($result, ['reason_code' => 'installation_attestation_expired']);
            }
            $directory = $profile['private_directory'] ?? null;
            if (! is_string($directory) || strlen($directory) > 512 || ! str_starts_with($directory, '/')
                || realpath($directory) !== $directory || is_link($directory) || ! is_dir($directory)
                || str_starts_with($directory.'/', rtrim(realpath(public_path()) ?: public_path(), '/').'/')
                || fileowner($directory) !== posix_geteuid() || (fileperms($directory) & 0777) !== 0700
                || ! is_readable($directory) || ! is_writable($directory)) {
                return array_replace($result, ['reason_code' => 'private_store_ownership_or_permissions']);
            }

            return ['supported' => true, 'enrolled' => true, 'reason_code' => 'installation_supported',
                'installation_id' => $id, 'private_directory' => $directory,
                'runtime_contract' => self::CONTRACT, 'expires_at' => $expires?->toIso8601String()];
        } catch (\Throwable) {
            return array_replace($result, ['reason_code' => 'installation_attestation_unavailable']);
        }
    }

    private function rootArtifact(string $path): bool
    {
        return $path !== '' && is_dir(dirname($path)) && fileowner(dirname($path)) === 0
            && (fileperms(dirname($path)) & 0022) === 0 && is_file($path) && ! is_link($path) && realpath($path) === $path
            && filesize($path) <= 4096 && fileowner($path) === 0 && (fileperms($path) & 0022) === 0
            && is_readable($path);
    }
}
