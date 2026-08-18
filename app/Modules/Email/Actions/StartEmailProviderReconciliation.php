<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Jobs\ReconcileEmailProviderAccount;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderReconciliationBindingPolicy;
use App\Modules\Email\Services\EmailProviderReconciliationPolicy;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StartEmailProviderReconciliation
{
    public function __construct(
        private readonly EmailProviderReconciliationBindingPolicy $bindings,
        private readonly EmailProviderReconciliationPolicy $policy,
        private readonly InboundEmailNotificationFanoutReadiness $fanoutReadiness,
    ) {}

    public function handle(
        EmailAccount $account,
        string $trigger,
        ?User $requester = null,
        ?string $idempotencyToken = null,
        ?int $maxFolders = null,
        ?int $uidBatchSize = null,
        ?int $normalIntervalSeconds = null,
        bool $dispatch = true,
    ): EmailProviderReconciliationRun {
        if (! in_array($trigger, [
            EmailProviderReconciliationRun::TRIGGER_SCHEDULED,
            EmailProviderReconciliationRun::TRIGGER_IDLE,
            EmailProviderReconciliationRun::TRIGGER_MANUAL,
            EmailProviderReconciliationRun::TRIGGER_CATCHUP,
        ], true)) {
            throw new InvalidArgumentException('Unknown provider reconciliation trigger.');
        }
        if (! $this->fanoutReadiness->ready()) {
            throw new EmailProviderReconciliationReadException(
                'inbound_notification_fanout_schema_not_ready',
            );
        }

        $account = $account->fresh();
        if (! $account || ! $account->is_active || $account->provider_runtime_paused_at
            || ! $this->bindings->databaseEligible($account)) {
            throw new EmailProviderReconciliationReadException('email_account_provider_not_ready');
        }
        $bindingVersion = $this->bindings->capture($account);
        $bounds = $this->policy->bounds($maxFolders, $uidBatchSize, $normalIntervalSeconds);
        // Human-triggered runs are distinct unless the caller deliberately
        // supplies an idempotency token. Automated triggers use a time bucket
        // so duplicated scheduler/IDLE delivery cannot create extra cycles.
        $token = $idempotencyToken ?? ($trigger === EmailProviderReconciliationRun::TRIGGER_MANUAL
            ? (string) Str::uuid()
            : sprintf(
                '%s:%d',
                $trigger,
                intdiv(now()->getTimestamp(), $bounds['normal_interval_seconds']),
            ));
        $idempotencyKey = hash('sha256', implode('|', [
            'email-provider-reconciliation',
            (int) $account->id,
            $trigger,
            $token,
            $bindingVersion,
        ]));
        $run = DB::transaction(function () use (
            $account,
            $requester,
            $trigger,
            $bindingVersion,
            $bounds,
            $idempotencyKey,
        ): EmailProviderReconciliationRun {
            $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
            if (! $lockedAccount->is_active || $lockedAccount->provider_runtime_paused_at
                || ! $this->bindings->databaseEligible($lockedAccount)) {
                throw new EmailProviderReconciliationReadException('email_account_provider_not_ready');
            }
            if ($this->bindings->capture($lockedAccount) !== $bindingVersion) {
                throw new EmailProviderReconciliationReadException('provider_binding_stale');
            }

            $active = EmailProviderReconciliationRun::query()
                ->where('account_id', $lockedAccount->id)
                ->where('active_slot', 1)
                ->first();
            if ($active) {
                return $active;
            }

            $existing = EmailProviderReconciliationRun::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            return EmailProviderReconciliationRun::query()->create([
                'account_id' => $lockedAccount->id,
                'requested_by' => $requester?->id,
                'provider' => 'imap',
                'trigger' => $trigger,
                'status' => EmailProviderReconciliationRun::STATUS_QUEUED,
                'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_START,
                'active_slot' => 1,
                'idempotency_key' => $idempotencyKey,
                'provider_binding_version' => $bindingVersion,
                ...$bounds,
                'queued_at' => now(),
            ]);
        }, 3);

        // Returning an existing active run is also a recovery signal. Queue
        // uniqueness coalesces healthy work, while a lost worker/job is woken
        // without creating a second account cycle.
        if ($dispatch && ! $run->terminal()) {
            ReconcileEmailProviderAccount::dispatch((int) $run->id)->afterCommit();
        }

        return $run->refresh();
    }
}
