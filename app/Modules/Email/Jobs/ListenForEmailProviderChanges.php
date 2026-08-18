<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Actions\StartEmailProviderReconciliation;
use App\Modules\Email\Contracts\EmailProviderIdleHintReader;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderReconciliationBindingPolicy;
use App\Modules\Email\Support\EmailProviderIdlePresenceLease;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ListenForEmailProviderChanges implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PRESENCE_SHUTDOWN_GRACE_SECONDS = 10;

    public int $timeout = 35;

    public int $tries = 3;

    public int $uniqueFor = 40;

    public ?int $providerBindingVersion = null;

    public function __construct(
        public int $accountId,
        ?int $providerBindingVersion = null,
    ) {
        $this->providerBindingVersion = $providerBindingVersion;
        $this->onQueue('email-idle');
    }

    public function uniqueId(): string
    {
        return 'email-provider-idle-listener:'.$this->accountId;
    }

    /**
     * Intentionally no EmailAccountProviderLock middleware: IDLE is a short
     * latency hint and must not block polling, sends, or reconciliation while
     * it waits. Its opaque result only queues the normal locked workflow.
     */
    public function handle(
        EmailProviderIdleHintReader $hints,
        EmailProviderReconciliationBindingPolicy $bindings,
        StartEmailProviderReconciliation $start,
    ): void {
        if (($this->providerBindingVersion ?? 0) < 1) {
            return;
        }

        $bindingVersion = (int) $this->providerBindingVersion;

        $account = EmailAccount::query()->find($this->accountId);
        if (! $account || ! $account->is_active || $account->provider_runtime_paused_at
            || $bindings->capture($account) !== $bindingVersion) {
            return;
        }

        // Outlive the worker timeout briefly so process-shutdown jitter cannot
        // expose a false drained state while the provider socket still exists.
        // A killed worker still releases automatically after this bounded TTL.
        $presence = EmailProviderIdlePresenceLease::acquire(
            $this->accountId,
            $this->timeout + self::PRESENCE_SHUTDOWN_GRACE_SECONDS,
        );
        if (! $presence) {
            return;
        }

        try {
            // Recheck after acquiring presence so a pause that won the race
            // prevents any new provider socket from opening.
            $account = EmailAccount::query()->find($this->accountId);
            if (! $account || ! $account->is_active || $account->provider_runtime_paused_at
                || $bindings->capture($account) !== $bindingVersion) {
                return;
            }
            $hinted = $hints->waitForOpaqueHint(
                $this->accountId,
                $bindingVersion,
                25,
            );
        } finally {
            $presence->release();
        }

        if (! $hinted) {
            return;
        }

        $account = EmailAccount::query()->find($this->accountId);
        if (! $account || ! $account->is_active || $account->provider_runtime_paused_at
            || $bindings->capture($account) !== $bindingVersion) {
            return;
        }

        $start->handle(
            $account,
            EmailProviderReconciliationRun::TRIGGER_IDLE,
        );
    }
}
