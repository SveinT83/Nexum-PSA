<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Contracts\WebPushQueueEvidence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Read-only setup gates, plus explicit same-installation trusted worker validation. No push is sent here. */
final class WebPushSetupChecks
{
    public function __construct(private readonly WebPushManagedRuntime $runtime, private readonly WebPushQueueEvidence $queue) {}

    public function context(): array
    {
        $identity = $this->runtime->summary();
        $queue = $this->queue->get();
        $origin = $this->origin();
        $evidence = null;
        try {
            $evidence = Cache::get($this->key($identity['identity_version'] ?? 'uninitialized'));
        } catch (\Throwable) {
        }
        $valid = $this->validEvidence($evidence, $identity, $queue, $origin);
        $trusted = $valid ? ($evidence['trusted_worker'] ?? false) === true : false;
        $worker = $valid && ($evidence['worker_state'] ?? null) === 'verified';

        return ['identity' => $identity, 'queue' => $queue, 'secure_origin' => $origin !== null,
            'service_worker' => $trusted ? 'verified' : ($origin === null ? 'insecure_origin' : 'not_verified'),
            'worker' => $worker ? 'verified' : ($valid ? ($evidence['worker_state'] ?? 'pending') : 'not_verified'),
            'check_id' => $valid ? ($evidence['check_id'] ?? null) : null,
            'checked_at' => $valid ? ($evidence['checked_at'] ?? null) : null,
            'ready_to_enable' => $identity['managed'] && $identity['identity_valid'] && $identity['issues'] === [] && $identity['installation_supported']
                && ! $identity['force_disabled'] && ! $identity['registration_reset_required']
                && $queue['state'] === 'healthy' && $trusted && $worker];
    }

    /** Explicit action only. An HTTP 200 HTML login page is never a service-worker success. */
    public function trustedWorker(): array
    {
        $origin = $this->origin();
        if ($origin === null) {
            return ['verified' => false, 'reason_code' => 'trusted_https_required'];
        }
        $path = public_path('sw.js');
        if (! is_file($path) || is_link($path) || filesize($path) > 524288) {
            return ['verified' => false, 'reason_code' => 'service_worker_asset_missing'];
        }
        try {
            $expected = file_get_contents($path);
            if (! str_contains($expected, 'push') || ! str_contains($expected, 'notificationclick')) {
                return ['verified' => false, 'reason_code' => 'service_worker_contract_missing'];
            }
            $response = Http::accept('application/javascript')->withoutRedirecting()->connectTimeout(3)->timeout(8)->get($origin.'/sw.js');
            $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
            if ($response->status() === 404) {
                return ['verified' => false, 'reason_code' => 'service_worker_asset_missing'];
            }
            if ($response->status() !== 200 || ! in_array($type, ['application/javascript', 'text/javascript'], true)
                || ! hash_equals(hash('sha256', $expected), hash('sha256', $response->body()))) {
                return ['verified' => false, 'reason_code' => 'service_worker_readback_mismatch'];
            }

            return ['verified' => true, 'reason_code' => 'trusted_service_worker_verified', 'asset_hash' => hash('sha256', $expected)];
        } catch (\Throwable $error) {
            // Inspect only numeric transport metadata; never copy an error message, URL or response body.
            $cursor = $error;
            for ($depth = 0; $cursor && $depth < 4; $depth++, $cursor = $cursor->getPrevious()) {
                if (method_exists($cursor, 'getHandlerContext')) {
                    $errno = $cursor->getHandlerContext()['errno'] ?? null;
                    if (in_array($errno, [51, 58, 60, 77, 83], true)) {
                        return ['verified' => false, 'reason_code' => 'trusted_https_untrusted'];
                    }
                }
            }

            return ['verified' => false, 'reason_code' => 'trusted_service_worker_unavailable'];
        }
    }

    public function key(string $identityVersion): string
    {
        return 'notification:webpush:setup:'.hash('sha256', \App\Modules\Notification\Support\LinuxWebPushInstallationProfile::installationId().':'.$identityVersion);
    }

    public function origin(): ?string
    {
        $url = config('app.url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null
            || ! in_array(parse_url($url, PHP_URL_PATH), [null, '', '/'], true)) {
            return null;
        }

        return rtrim($url, '/');
    }

    private function validEvidence(mixed $evidence, array $identity, array $queue, ?string $origin): bool
    {
        if (! is_array($evidence) || $origin === null || ! is_string($evidence['checked_at'] ?? null)
            || ($evidence['identity_version'] ?? null) !== $identity['identity_version']
            || ($evidence['origin_hash'] ?? null) !== hash('sha256', $origin)
            || ($evidence['scope_hash'] ?? null) !== $queue['scope_hash']
            || ($evidence['public_fingerprint'] ?? null) !== $identity['public_fingerprint']) {
            return false;
        }
        try {
            $age = now()->getTimestamp() - \Carbon\CarbonImmutable::parse($evidence['checked_at'])->getTimestamp();
            if ($age < 0 || $age > max(60, min(600, (int) config('webpush_setup.worker_ack_seconds', 300)))) {
                return false;
            }
            $path = public_path('sw.js');

            return is_file($path) && ! is_link($path) && filesize($path) <= 524288
                && ($evidence['asset_hash'] ?? null) === hash_file('sha256', $path);
        } catch (\Throwable) {
            return false;
        }
    }
}
