<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Email\Support\EmailProviderIdlePresenceLease;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PauseEmailProviderAccountRuntime
{
    public function __construct(private readonly EmailProviderManagementAuthorization $authorization) {}

    public function execute(User $actor, EmailAccount $account, string $reasonCode): EmailAccount
    {
        $actor = $this->authorization->authorizeBinding($actor);

        if (preg_match('/^[a-z0-9_.-]{1,80}$/', $reasonCode) !== 1) {
            throw new EmailProviderSecurityException('pause_reason_invalid');
        }

        $pausedAt = DB::transaction(function () use ($actor, $account, $reasonCode) {
            $locked = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);

            if (! $locked->provider_runtime_paused_at) {
                $locked->forceFill([
                    'provider_runtime_paused_at' => now(),
                    'provider_runtime_drained_at' => null,
                    'provider_runtime_paused_by' => $actor->id,
                    'provider_runtime_pause_reason_code' => $reasonCode,
                ])->save();
            }

            return (string) $locked->fresh()->getRawOriginal('provider_runtime_paused_at');
        }, 3);

        // IDLE deliberately does not own the ordinary provider-operation lock.
        // Owning its separate presence token before the provider lock proves
        // every socket is closed before this pause is marked drained.
        $idleBarrier = EmailProviderIdlePresenceLease::acquire((int) $account->id, 150);

        if (! $idleBarrier) {
            // The pause remains durable so queued work fails closed. A retry
            // marks the drain complete after the current lease finishes.
            throw new EmailProviderSecurityException('provider_work_draining');
        }

        $providerLock = null;

        try {
            $providerLock = EmailAccountProviderLock::acquire((int) $account->id, 120);

            if (! $providerLock) {
                throw new EmailProviderSecurityException('provider_work_draining');
            }

            if (Schema::hasTable('email_provider_reconciliation_runs')
                && EmailProviderReconciliationRun::accountHasActiveRun((int) $account->id)) {
                throw new EmailProviderSecurityException('provider_reconciliation_active');
            }

            return DB::transaction(function () use ($account, $pausedAt): EmailAccount {
                $locked = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);

                if ((string) $locked->getRawOriginal('provider_runtime_paused_at') !== $pausedAt) {
                    throw new EmailProviderSecurityException('provider_pause_changed');
                }

                $locked->forceFill(['provider_runtime_drained_at' => now()])->save();

                return $locked->fresh();
            }, 3);
        } finally {
            $providerLock?->release();
            $idleBarrier->release();
        }
    }
}
