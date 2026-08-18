<?php

namespace App\Modules\Integration\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Email\Support\EmailProviderIdlePresenceLease;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class EmailProviderLifecycleAccountLocks
{
    private const PROVIDER_BINDING_MUTEX_PREFIX = 'email-provider-lifecycle-binding:';

    /** @return list<Lock> */
    public function acquire(#[\SensitiveParameter] string $providerIntegrationId): array
    {
        $leaseSeconds = $this->leaseSeconds();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $locks = [];

            try {
                // Serialize the bound-account snapshot with every writer that
                // can bind or unbind an account. Without this provider-global
                // mutex, a new account could appear after the stable-list
                // check and race an activation or revoke.
                $bindingMutex = Cache::lock(
                    self::PROVIDER_BINDING_MUTEX_PREFIX.$providerIntegrationId,
                    $leaseSeconds,
                );
                if (! $bindingMutex->get()) {
                    throw new EmailProviderSecurityException('provider_lifecycle_locked');
                }
                $locks[] = $bindingMutex;

                $accountIds = $this->boundAccountIds($providerIntegrationId);

                foreach ($accountIds as $accountId) {
                    $idleBarrier = EmailProviderIdlePresenceLease::acquire($accountId, $leaseSeconds);
                    if (! $idleBarrier) {
                        throw new EmailProviderSecurityException('provider_work_not_drained');
                    }

                    $locks[] = $idleBarrier;

                    $providerLock = EmailAccountProviderLock::acquire($accountId, $leaseSeconds);
                    if (! $providerLock) {
                        throw new EmailProviderSecurityException('provider_work_not_drained');
                    }

                    $locks[] = $providerLock;

                    if (Schema::hasTable('email_provider_reconciliation_runs')
                        && EmailProviderReconciliationRun::accountHasActiveRun($accountId)) {
                        throw new EmailProviderSecurityException('provider_reconciliation_active');
                    }
                }

                if ($accountIds === $this->boundAccountIds($providerIntegrationId)) {
                    return $locks;
                }
            } catch (\Throwable $exception) {
                $this->release($locks);

                if ($exception instanceof EmailProviderSecurityException) {
                    throw $exception;
                }

                throw new EmailProviderSecurityException('provider_lifecycle_lock_failed');
            }

            $this->release($locks);
        }

        throw new EmailProviderSecurityException('provider_binding_changed_during_lifecycle_lock');
    }

    /**
     * The lifecycle lease must outlive the longest verification probe, its
     * separately bounded cleanup, and database finalization. Keep every
     * provider-global, IDLE, and ordinary account barrier on the same budget.
     */
    public function leaseSeconds(): int
    {
        $deadline = max(
            2,
            min(120, (int) config('email_provider_security.verification_deadline_seconds', 60)),
        );
        $cleanup = max(
            1,
            min(5, (int) config('email_provider_security.verification_cleanup_grace_seconds', 2)),
        );

        return max(180, $deadline + $cleanup + 60);
    }

    /** @param list<Lock> $locks */
    public function release(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Lease expiry remains fail-safe; cleanup cannot expose state.
            }
        }
    }

    /** @return list<int> */
    private function boundAccountIds(string $providerIntegrationId): array
    {
        return EmailAccount::query()
            ->where('provider_credential_source', 'integration')
            ->where('provider_integration_id', $providerIntegrationId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
