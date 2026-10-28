<?php

namespace App\Modules\Notification\Jobs;

use App\Models\Core\User;
use App\Modules\Notification\Channels\ManagedWebPushChannel;
use App\Modules\Notification\Contracts\WebPushQueueEvidence;
use App\Modules\Notification\Services\WebPushManagedRuntime;
use App\Modules\Notification\Support\LinuxWebPushInstallationProfile;
use App\Modules\Notification\Support\WebPushSetupAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use NotificationChannels\WebPush\WebPushChannel;

/** No message or key payload: verify only the exact worker/runtime/identity contract from an authorized check. */
final class VerifyManagedWebPushRuntime implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public readonly string $cacheKey, public readonly string $checkId)
    {
        $this->onQueue('default');
    }

    public function handle(WebPushManagedRuntime $runtime, WebPushQueueEvidence $queues): void
    {
        if (! preg_match('/\Anotification:webpush:setup:[a-f0-9]{64}\z/D', $this->cacheKey)
            || ! \Illuminate\Support\Str::isUuid($this->checkId)) {
            return;
        }
        try {
            $receipt = Cache::get($this->cacheKey);
            if (! is_array($receipt) || ($receipt['check_id'] ?? null) !== $this->checkId
                || ($receipt['worker_state'] ?? null) !== 'pending') {
                return;
            }
            $actor = User::find($receipt['actor_id'] ?? 0);
            $allowed = WebPushSetupAccess::allows($actor);
            if ($receipt['token_id'] ?? null) {
                $token = PersonalAccessToken::find($receipt['token_id']);
                $allowed = $allowed && $token && (int) $token->tokenable_id === (int) $actor?->id
                    && $token->tokenable_type === $actor?->getMorphClass()
                    && (! $token->expires_at || $token->expires_at->isFuture()) && $token->can('notifications.channels.test');
            }
            $identity = $runtime->summary();
            $queue = $queues->get();
            $age = now()->getTimestamp() - \Carbon\CarbonImmutable::parse($receipt['checked_at'] ?? '1970-01-01')->getTimestamp();
            $channel = app(WebPushChannel::class);
            $verified = $allowed && $age >= 0 && $age <= 300 && $queue['state'] === 'healthy'
                && $identity['managed'] && $identity['installation_supported'] && $identity['identity_valid']
                && ($receipt['identity_version'] ?? null) === $identity['identity_version']
                && ($receipt['scope_hash'] ?? null) === $queue['scope_hash']
                && ($receipt['public_fingerprint'] ?? null) === $identity['public_fingerprint']
                && $channel instanceof ManagedWebPushChannel
                && $channel->runtimeContract() === LinuxWebPushInstallationProfile::CONTRACT
                && $channel->runtimeIdentityVersion() === $identity['identity_version'];
            $receipt['worker_state'] = $verified ? 'verified' : 'blocked';
            $receipt['worker_reason'] = $verified ? 'exact_worker_identity_verified' : 'worker_contract_or_authority_unverified';
            $receipt['worker_checked_at'] = now()->toIso8601String();
            Cache::put($this->cacheKey, $receipt, 600);
        } catch (\Throwable) {
            // No automatic retry and no raw queue/crypto diagnostics. An expired/missing receipt remains unverified.
        }
    }
}
