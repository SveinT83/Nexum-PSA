<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\DTOs\EmailProviderReconciliationBindingSnapshot;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use Illuminate\Support\Facades\DB;

final class EmailProviderReconciliationBindingPolicy
{
    public function __construct(
        private readonly EmailAccountProviderRuntimeResolver $runtime,
    ) {}

    public function capture(EmailAccount $account): int
    {
        return $this->runtime->captureBindingVersion($account);
    }

    public function databaseEligible(EmailAccount $account): bool
    {
        return $this->runtime->databaseReady($account);
    }

    public function currentAccount(EmailProviderReconciliationRun $run): EmailAccount
    {
        $account = EmailAccount::query()->find($run->account_id);

        if (! $account || ! $account->is_active) {
            throw new EmailProviderReconciliationReadException('email_account_inactive');
        }

        if ($account->provider_runtime_paused_at) {
            throw new EmailProviderReconciliationReadException('provider_runtime_paused');
        }

        if ($this->runtime->bindingVersion($account) !== (int) $run->provider_binding_version) {
            throw new EmailProviderReconciliationReadException('provider_binding_stale');
        }

        return $account;
    }

    /**
     * Attach the first resolved runtime atomically. Secret-only credential
     * rotation may change the active credential version later; binding or
     * endpoint/configuration drift makes the complete cycle stale.
     */
    public function recordResolvedRuntime(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationBindingSnapshot $snapshot,
    ): EmailProviderReconciliationRun {
        return DB::transaction(function () use ($run, $snapshot): EmailProviderReconciliationRun {
            $locked = EmailProviderReconciliationRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->terminal()
                || $locked->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || (int) $locked->active_slot !== 1
                || $locked->cancellation_requested_at !== null) {
                throw new EmailProviderReconciliationReadException(
                    'provider_reconciliation_run_inactive',
                );
            }
            if ((int) $locked->provider_binding_version !== $snapshot->bindingVersion) {
                throw new EmailProviderReconciliationReadException('provider_binding_stale');
            }

            if ($locked->provider_configuration_version === null) {
                $locked->forceFill([
                    'provider_configuration_version' => $snapshot->configurationVersion,
                    'provider_credential_version' => $snapshot->credentialVersion,
                    'provider_runtime_fingerprint' => $snapshot->runtimeFingerprint,
                ])->save();

                return $locked->refresh();
            }

            if ((int) $locked->provider_configuration_version !== $snapshot->configurationVersion
                || ! hash_equals(
                    (string) $locked->provider_runtime_fingerprint,
                    $snapshot->runtimeFingerprint,
                )) {
                throw new EmailProviderReconciliationReadException('provider_configuration_stale');
            }

            return $locked;
        }, 3);
    }
}
