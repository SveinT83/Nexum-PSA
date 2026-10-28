<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Contracts\WebPushQueueEvidence;
use App\Modules\Notification\Jobs\VerifyManagedWebPushRuntime;
use App\Modules\Notification\Support\WebPushSetupAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/** Explicit harmless probe on an already operator-approved healthy queue; never starts a worker or sends push. */
final class RunWebPushSetupCheck
{
    public function __construct(private readonly WebPushManagedRuntime $runtime, private readonly WebPushSetupChecks $checks,
        private readonly WebPushQueueEvidence $queues) {}

    public function run(Request $request, string $expected): array
    {
        $actor = WebPushSetupAccess::actor($request);
        $identity = $this->runtime->summary();
        abort_unless($identity['managed'] && $identity['installation_supported'] && $identity['identity_valid'] && $identity['issues'] === [], 409, 'Initialize supported stable Web Push identity before checking its worker.');
        abort_unless(hash_equals($identity['revision'], $expected), 412, 'Web Push settings changed; reload before checking.');
        $queue = $this->queues->get();
        abort_unless($queue['state'] === 'healthy', 409, 'The exact default queue needs fresh operator-approved worker evidence.');
        $trusted = $this->checks->trustedWorker();
        $failure = match ($trusted['reason_code']) {
            'trusted_https_required' => 'A trusted HTTPS installation origin is required before checking Web Push.',
            'trusted_https_untrusted' => 'The installation HTTPS certificate could not be trusted. Its owner must repair TLS before continuing.',
            'service_worker_asset_missing', 'service_worker_contract_missing' => 'The shared Web Push service-worker asset is missing or incomplete. Its owner must deploy the supported asset.',
            'service_worker_readback_mismatch' => 'The installation returned a different or unsupported service worker. A login page or HTTP success alone is not readiness.',
            default => 'Trusted HTTPS/service-worker read-back is unavailable. Inspect installation connectivity and TLS before continuing.',
        };
        abort_unless($trusted['verified'], 409, $failure);
        WebPushSetupAccess::actor($request);
        $currentQueue = $this->queues->get();
        abort_unless($this->runtime->summary()['revision'] === $expected && $currentQueue['state'] === 'healthy'
            && $currentQueue['scope_hash'] === $queue['scope_hash'], 412, 'Setup scope changed while checking; inspect current state.');
        $cacheKey = $this->checks->key($identity['identity_version']);
        $checkId = (string) Str::uuid();
        // The bounded installation claim prevents repeated button presses from creating a queue canary burst.
        abort_unless(Cache::add($cacheKey.':claim', $checkId, 60), 409, 'A setup check is already pending; refresh its recorded result.');
        $token = $request->user()->currentAccessToken();
        $receipt = ['check_id' => $checkId, 'actor_id' => $actor->id,
            'token_id' => $token instanceof PersonalAccessToken ? $token->id : null,
            'revision' => $expected, 'identity_version' => $identity['identity_version'], 'origin_hash' => hash('sha256', $this->checks->origin()),
            'scope_hash' => $queue['scope_hash'], 'public_fingerprint' => $identity['public_fingerprint'],
            'asset_hash' => $trusted['asset_hash'], 'trusted_worker' => true,
            'checked_at' => now()->toIso8601String(), 'worker_state' => 'pending', 'worker_reason' => 'worker_readback_pending'];
        Cache::put($cacheKey, $receipt, 600);
        try {
            Bus::dispatch((new VerifyManagedWebPushRuntime($cacheKey, $checkId))->onConnection($queue['connection']));
        } catch (\Throwable) {
            $receipt['worker_state'] = 'unknown';
            $receipt['worker_reason'] = 'worker_probe_dispatch_unknown';
            Cache::put($cacheKey, $receipt, 600);
        }

        return $this->checks->context();
    }
}
