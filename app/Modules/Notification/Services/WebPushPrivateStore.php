<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Contracts\WebPushInstallationProfile;
use Illuminate\Support\Str;
use RuntimeException;

/** Encrypted installation artifacts only; no plaintext key or raw filesystem error escapes this boundary. */
final class WebPushPrivateStore
{
    public function __construct(private readonly WebPushInstallationProfile $profile) {}

    /** Internal secret-bearing state. Controllers must use the runtime's allowlisted summary instead. */
    public function read(): array
    {
        $profile = $this->profile->resolve();
        if (! $profile['supported']) {
            return ['state' => ($profile['enrolled'] ?? false) ? 'unavailable' : 'legacy', 'profile' => $profile, 'identity' => null];
        }
        try {
            $path = $profile['private_directory'].'/current.enc';
            if (! file_exists($path) && ! is_link($path)) {
                return ['state' => 'uninitialized', 'profile' => $profile, 'identity' => null];
            }
            $identity = $this->decode($path, $profile);

            return ['state' => 'configured', 'profile' => $profile, 'identity' => $identity];
        } catch (\Throwable) {
            return ['state' => 'unavailable', 'profile' => $profile, 'identity' => null];
        }
    }

    /**
     * Claim exact actor/action identity before changing an encrypted installation artifact.
     * The callback returns a new secret-bearing identity; only the supplied safe summary is retained in its receipt.
     */
    public function change(string $actionKey, string $payloadHash, ?string $expectedRevision, \Closure $change, \Closure $summarize, ?\Closure $authorize = null): array
    {
        $profile = $this->profile->resolve();
        abort_unless($profile['supported'], 409, 'This installation has not provisioned supported managed Web Push storage.');
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $actionKey) && preg_match('/\A[a-f0-9]{64}\z/D', $payloadHash), 422);
        $lock = null;
        try {
            $directory = $profile['private_directory'];
            $lockPath = $directory.'/setup.lock';
            $lock = $this->lock($lockPath);
            $receiptPath = $directory.'/action-'.$actionKey.'.enc';
            if (file_exists($receiptPath) || is_link($receiptPath)) {
                $receipt = $this->decode($receiptPath, $profile, false);
                abort_unless(hash_equals($receipt['payload_hash'] ?? '', $payloadHash), 409, 'The action identity belongs to another Web Push request.');

                return $receipt['result'] + ['replayed' => true];
            }
            $state = $this->read();
            abort_unless($this->profile->resolve() === $profile, 409, 'The installation capability changed; reload before another action.');
            abort_unless(in_array($state['state'], ['uninitialized', 'configured'], true), 409, 'Managed Web Push state cannot be read safely.');
            $current = $state['identity'];
            if (($current['last_action']['key'] ?? null) === $actionKey) {
                abort_unless(hash_equals($current['last_action']['payload_hash'] ?? '', $payloadHash), 409, 'The action identity belongs to another Web Push request.');
                $result = $current['last_action']['result'];
                $this->write($receiptPath, ['installation_id' => $profile['installation_id'], 'payload_hash' => $payloadHash, 'result' => $result], $profile, false);

                return $result + ['replayed' => true];
            }
            abort_unless(hash_equals($current['revision'] ?? 'uninitialized', $expectedRevision ?? ''), 412, 'Web Push settings changed; reload before another action.');
            $next = $change($current, $profile);
            if (! is_array($next)) {
                throw new RuntimeException('Invalid managed identity result.');
            }
            $next['installation_id'] = $profile['installation_id'];
            $next['revision'] = (string) Str::uuid();
            $next['format'] = 1;
            $next['updated_at'] = now()->toIso8601String();
            $result = $summarize($next);
            $next['last_action'] = ['key' => $actionKey, 'payload_hash' => $payloadHash, 'result' => $result];
            abort_unless($this->profile->resolve() === $profile, 409, 'The installation capability changed before publication.');
            if ($authorize !== null) {
                $authorize();
            }
            if ($current !== null) {
                $this->write($directory.'/identity-'.$current['revision'].'.enc', $current, $profile, false);
            }
            $this->write($directory.'/current.enc', $next, $profile, true);
            $this->write($receiptPath, ['installation_id' => $profile['installation_id'], 'payload_hash' => $payloadHash, 'result' => $result], $profile, false);

            return $result + ['replayed' => false];
        } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable) {
            // Never chain crypto/key/transport-bearing errors into ordinary diagnostics.
            throw new RuntimeException('Managed Web Push storage outcome is unavailable; reconcile the exact action before retrying.');
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Internal immutable action metadata only; lifecycle replays must not repeat device cleanup. */
    public function receipt(string $actionKey, string $payloadHash): ?array
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $actionKey) && preg_match('/\A[a-f0-9]{64}\z/D', $payloadHash), 422);
        $profile = $this->profile->resolve();
        abort_unless($profile['supported'], 409, 'The installation profile is unavailable.');
        $path = $profile['private_directory'].'/action-'.$actionKey.'.enc';
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        try {
            $receipt = $this->decode($path, $profile, false);
        } catch (\Throwable) {
            throw new RuntimeException('The protected action receipt is unavailable; reconcile before retrying.');
        }
        abort_unless(hash_equals($receipt['payload_hash'] ?? '', $payloadHash), 409, 'The action identity belongs to another Web Push request.');

        return $receipt['result'];
    }

    public function historical(string $revision): array
    {
        abort_unless(Str::isUuid($revision), 422, 'Choose an exact historical identity revision.');
        $profile = $this->profile->resolve();
        abort_unless($profile['supported'], 409, 'The installation profile is unavailable.');
        $path = $profile['private_directory'].'/identity-'.$revision.'.enc';
        abort_unless(file_exists($path) || is_link($path), 404, 'The selected encrypted identity revision does not exist.');
        try {
            return $this->decode($path, $profile);
        } catch (\Throwable) {
            throw new RuntimeException('The selected encrypted identity revision cannot be read safely.');
        }
    }

    private function lock(string $path)
    {
        if (file_exists($path) || is_link($path)) {
            $this->assertPrivateFile($path);
        } else {
            $old = umask(0077);
            try {
                $created = @fopen($path, 'x');
            } finally {
                umask($old);
            }
            if (is_resource($created)) {
                fclose($created);
            }
        }
        $this->assertPrivateFile($path);
        $lock = @fopen($path, 'r+');
        if (! is_resource($lock)) {
            throw new RuntimeException('Private lock unavailable.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            abort(409, 'Another managed Web Push action is in progress; retain the original action identity.');
        }

        return $lock;
    }

    private function decode(string $path, array $profile, bool $identity = true): array
    {
        $this->assertPrivateFile($path);
        if (filesize($path) > 1048576) {
            throw new RuntimeException('Encrypted artifact exceeds its bound.');
        }
        $value = decrypt(file_get_contents($path));
        if (! is_array($value) || ($value['installation_id'] ?? null) !== $profile['installation_id']
            || ($identity && (($value['format'] ?? null) !== 1 || ! Str::isUuid($value['revision'] ?? '')))) {
            throw new RuntimeException('Encrypted artifact identity mismatch.');
        }

        return $value;
    }

    private function assertPrivateFile(string $path): void
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path) || realpath($path) !== $path
            || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0777) !== 0600) {
            throw new RuntimeException('Private artifact ownership or permissions mismatch.');
        }
    }

    private function write(string $path, #[\SensitiveParameter] array $value, array $profile, bool $replace): void
    {
        if (file_exists($path) || is_link($path)) {
            $this->assertPrivateFile($path);
            if (! $replace) {
                // Immutable history/receipts can only be replayed with the exact original content.
                if ($this->decode($path, $profile, false) !== $value) {
                    throw new RuntimeException('Immutable encrypted history conflict.');
                }

                return;
            }
        }
        $temporary = $profile['private_directory'].'/.write-'.bin2hex(random_bytes(16));
        $old = umask(0077);
        try {
            $file = @fopen($temporary, 'x');
            if (! is_resource($file)) {
                throw new RuntimeException('Private write unavailable.');
            }
            try {
                $ciphertext = encrypt($value);
                if (fwrite($file, $ciphertext) !== strlen($ciphertext) || ! fflush($file)) {
                    throw new RuntimeException('Private write incomplete.');
                }
                if (function_exists('fsync')) {
                    fsync($file);
                }
            } finally {
                fclose($file);
            }
            $this->assertPrivateFile($temporary);
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Atomic private publish failed.');
            }
            if ($this->decode($path, $profile, false) !== $value) {
                throw new RuntimeException('Encrypted artifact read-back mismatch.');
            }
        } finally {
            umask($old);
            if (is_file($temporary) && ! is_link($temporary)) {
                unlink($temporary);
            }
        }
    }
}
