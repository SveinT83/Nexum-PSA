<?php

namespace App\Modules\Notification\Commands;

use App\Modules\Notification\Support\LinuxWebPushInstallationProfile;
use Illuminate\Console\Command;

/** Installation owner attestation only. No key generation, activation, .env write or process restart. */
final class ApproveWebPushInstallation extends Command
{
    protected $signature = 'notification:webpush:approve-installation
        {--runtime-user= : Existing non-root PHP runtime account}
        {--private-directory= : Existing dedicated runtime-owned 0700 private directory}
        {--operator= : Named operator who verified the entire worker and web runtime rollout}
        {--days= : Optional operator-chosen expiry (1 to 365 days; omitted means no recurring renewal)}
        {--confirm= : Type WORKERS AND WEB RUNTIME RELOADED after actual verification}';

    protected $description = 'Record an explicit Linux installation capability after the operator verified its protected volume and complete runtime rollout';

    public function handle(): int
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->error('Only the installation owner running as root can attest managed Web Push support.');

            return self::FAILURE;
        }
        if ($this->option('confirm') !== 'WORKERS AND WEB RUNTIME RELOADED') {
            $this->error('Verify and reload the entire web/worker runtime first, then supply the exact attestation phrase.');

            return self::FAILURE;
        }
        $operator = $this->option('operator');
        $account = $this->option('runtime-user');
        $directory = $this->option('private-directory');
        $requestedDays = $this->option('days');
        $days = $requestedDays === null || $requestedDays === '' ? null : filter_var($requestedDays, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        $user = is_string($account) && preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $account) ? posix_getpwnam($account) : false;
        if (! is_string($operator) || trim($operator) === '' || strlen($operator) > 100 || preg_match('/[\x00-\x1f\x7f]/', $operator)
            || ! $user || $user['uid'] === 0 || $days === false || ! is_string($directory)
            || strlen($directory) > 512 || preg_match('/[\x00-\x1f\x7f]/', $directory)
            || ! str_starts_with($directory, '/') || realpath($directory) !== $directory || is_link($directory) || ! is_dir($directory)
            || fileowner($directory) !== $user['uid'] || (fileperms($directory) & 0777) !== 0700
            || $this->inside($directory, public_path())) {
            $this->error('Provide a named operator, non-root runtime account, bounded validity and its existing dedicated private 0700 directory.');

            return self::FAILURE;
        }
        $capability = (string) config('webpush_setup.capability_path');
        $enrollment = (string) config('webpush_setup.enrollment_path');
        if ($capability === $enrollment || ! $this->rootTarget($capability) || ! $this->rootTarget($enrollment)) {
            $this->error('Provision root-owned non-writable installation config directories outside the application before recording this attestation.');

            return self::FAILURE;
        }
        $id = LinuxWebPushInstallationProfile::installationId();
        try {
            foreach ([$capability, $enrollment] as $path) {
                if (file_exists($path)) {
                    $old = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
                    if (! is_array($old) || ($old['installation_id'] ?? null) !== $id) {
                        throw new \RuntimeException('Existing installation metadata belongs to another installation.');
                    }
                }
            }
            $at = now('UTC');
            $profile = ['version' => 1, 'installation_id' => $id, 'private_directory' => $directory,
                'runtime_contract' => LinuxWebPushInstallationProfile::CONTRACT, 'secret_format' => 'laravel-app-key-v1',
                'operator' => trim($operator), 'workers_reloaded_at' => $at->toIso8601String(),
                'expires_at' => $days === null ? null : $at->copy()->addDays($days)->toIso8601String()];
            if (! file_exists($enrollment)) {
                $this->publish($enrollment, ['installation_id' => $id, 'version' => 1]);
            }
            $this->publish($capability, $profile);
            $read = json_decode(file_get_contents($capability), true, 8, JSON_THROW_ON_ERROR);
            if ($read !== $profile || fileowner($capability) !== 0 || (fileperms($capability) & 0777) !== 0644) {
                throw new \RuntimeException('Installation capability read-back failed.');
            }
            $this->info('Installation capability recorded and read back. No VAPID identity, global activation or browser consent changed.');
            $this->line('Complete the Admin setup/check workflow and separate named human review before production activation.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Installation capability could not be verified. Existing key material is untouched; reconcile the metadata before continuing.');

            return self::FAILURE;
        }
    }

    private function rootTarget(string $path): bool
    {
        if ($path === '' || ! str_starts_with($path, '/') || $this->inside($path, base_path())
            || realpath(dirname($path)) !== dirname($path) || fileowner(dirname($path)) !== 0
            || (fileperms(dirname($path)) & 0022) !== 0 || is_link($path)) {
            return false;
        }

        return ! file_exists($path) || (is_file($path) && fileowner($path) === 0 && (fileperms($path) & 0022) === 0 && filesize($path) <= 4096);
    }

    private function inside(string $path, string $root): bool
    {
        return str_starts_with(rtrim($path, '/').'/', rtrim(realpath($root) ?: $root, '/').'/');
    }

    private function publish(string $path, array $value): void
    {
        $temporary = dirname($path).'/.webpush-profile-'.bin2hex(random_bytes(12));
        $old = umask(0022);
        try {
            $file = @fopen($temporary, 'x');
            if (! is_resource($file)) {
                throw new \RuntimeException('Installation metadata write unavailable.');
            }
            try {
                $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($file, $json) !== strlen($json) || ! fflush($file)) {
                    throw new \RuntimeException('Installation metadata write incomplete.');
                }
                if (function_exists('fsync')) {
                    fsync($file);
                }
            } finally {
                fclose($file);
            }
            chmod($temporary, 0644);
            if (! @rename($temporary, $path)) {
                throw new \RuntimeException('Installation metadata publish failed.');
            }
        } finally {
            umask($old);
            if (is_file($temporary) && ! is_link($temporary)) {
                unlink($temporary);
            }
        }
    }
}
