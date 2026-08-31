<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Email\Support\EmailProviderIdlePresenceLease;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use App\Modules\Integration\Models\EmailProviderMigrationRun;
use App\Modules\Integration\Services\EmailProviderCutoverReadiness;
use App\Modules\Integration\Services\EmailProviderEventRecorder;
use App\Modules\Integration\Services\EmailProviderLegacyAccountMaterial;
use App\Modules\Integration\Services\EmailProviderLifecycleAccountLocks;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderMigrationScope;
use App\Modules\Integration\Services\EmailProviderRuntimeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RebindLegacyEmailAccountToProvider
{
    private const REPLACEABLE_BLOCKS = [
        'legacy_material_incomplete',
        'legacy_configuration_not_supported',
    ];

    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderMigrationScope $scope,
        private readonly EmailProviderLegacyAccountMaterial $legacy,
        private readonly EmailProviderRuntimeFactory $runtime,
        private readonly EmailProviderCutoverReadiness $readiness,
        private readonly EmailProviderEventRecorder $events,
        private readonly EmailProviderLifecycleAccountLocks $lifecycleLocks,
    ) {}

    /**
     * Bind one blocked legacy mailbox to an independently created, verified
     * provider. The legacy fields stay intact for the bounded rollback window.
     * This action is local-only and never contacts IMAP or SMTP.
     */
    public function execute(
        User $actor,
        EmailProviderMigrationRun $preview,
        EmailProviderMigrationItem $item,
        EmailProviderConnection $connection,
    ): EmailProviderMigrationRun {
        $actor = $this->authorization->authorizeBinding($actor);
        $actor = $this->authorization->authorizeConnectionTrust($actor, $connection);

        $existing = EmailProviderMigrationRun::query()
            ->where('operation', 'cutover')
            ->where('source_run_id', $preview->id)
            ->where('status', 'applied')
            ->latest('id')
            ->first();
        if ($existing) {
            return $existing->load('items');
        }

        $lifecycleLocks = $this->lifecycleLocks->acquire((string) $connection->getKey());
        $idleBarrier = null;
        $providerLock = null;

        try {
            $idleBarrier = EmailProviderIdlePresenceLease::acquire((int) $item->email_account_id, 180);
            if (! $idleBarrier) {
                throw new EmailProviderSecurityException('provider_work_not_drained');
            }

            $providerLock = EmailAccountProviderLock::acquire((int) $item->email_account_id, 180);
            if (! $providerLock) {
                throw new EmailProviderSecurityException('provider_work_not_drained');
            }

            return DB::transaction(function () use ($actor, $preview, $item, $connection): EmailProviderMigrationRun {
                $run = EmailProviderMigrationRun::query()->lockForUpdate()->findOrFail($preview->id);
                $lockedItem = EmailProviderMigrationItem::query()->lockForUpdate()->findOrFail($item->id);

                if ((int) $lockedItem->migration_run_id !== (int) $run->id
                    || $run->operation !== 'legacy_migration'
                    || $run->status !== 'previewed'
                    || $run->preview_expires_at?->isPast()
                    || (int) $run->account_count !== 1
                    || $lockedItem->status !== 'blocked'
                    || ! in_array((string) $lockedItem->block_code, self::REPLACEABLE_BLOCKS, true)) {
                    throw new EmailProviderSecurityException('replacement_preview_not_applicable');
                }

                $account = EmailAccount::query()->lockForUpdate()->findOrFail($lockedItem->email_account_id);
                if (! hash_equals((string) $run->scope_fingerprint, $this->scope->fingerprint([(int) $account->id]))
                    || (string) ($account->provider_credential_source ?: 'legacy') !== 'legacy'
                    || filled($account->provider_integration_id)
                    || ! hash_equals((string) $lockedItem->legacy_fingerprint, $this->legacy->legacyFingerprint($account))) {
                    throw new EmailProviderSecurityException('legacy_snapshot_stale');
                }

                if ($account->is_active) {
                    throw new EmailProviderSecurityException('replacement_account_must_be_disabled');
                }

                if (! $account->provider_runtime_paused_at
                    || ! $account->provider_runtime_drained_at
                    || Carbon::parse((string) $account->provider_runtime_drained_at)
                        ->lt(Carbon::parse((string) $account->provider_runtime_paused_at))) {
                    throw new EmailProviderSecurityException('provider_work_not_paused_drained');
                }

                if (Schema::hasTable('email_provider_reconciliation_runs')
                    && EmailProviderReconciliationRun::accountHasActiveRun((int) $account->id)) {
                    throw new EmailProviderSecurityException('provider_reconciliation_active');
                }

                if (EmailRemoteOperation::query()
                    ->where('account_id', $account->id)
                    ->whereIn('status', [
                        EmailRemoteOperation::STATUS_PENDING,
                        EmailRemoteOperation::STATUS_RUNNING,
                        EmailRemoteOperation::STATUS_FAILED,
                    ])
                    ->exists()
                    || $this->readiness->hasUnresolvedProviderWork($account)) {
                    throw new EmailProviderSecurityException('provider_binding_work_unresolved');
                }

                $lockedConnection = EmailProviderConnection::query()
                    ->lockForUpdate()
                    ->findOrFail($connection->getKey());
                $this->authorization->authorizeConnectionTrust($actor, $lockedConnection);
                $credential = $lockedConnection->active_credential_version_id
                    ? EmailProviderCredentialVersion::query()
                        ->lockForUpdate()
                        ->find($lockedConnection->active_credential_version_id)
                    : null;
                if (! $this->runtime->databaseReadySnapshot($lockedConnection, $credential)) {
                    throw new EmailProviderSecurityException('replacement_provider_not_ready');
                }

                $previousBindingVersion = (int) $account->provider_binding_version;
                $cutover = EmailProviderMigrationRun::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'operation' => 'cutover',
                    'status' => 'applied',
                    'scope_fingerprint' => $run->scope_fingerprint,
                    'account_count' => 1,
                    'ready_count' => 1,
                    'blocked_count' => 0,
                    'applied_count' => 1,
                    'created_by' => $actor->id,
                    'applied_by' => $actor->id,
                    'source_run_id' => $run->id,
                    'preview_expires_at' => now(),
                    'rollback_deadline_at' => now()->addDays(7),
                    'started_at' => now(),
                    'finished_at' => now(),
                ]);

                $account->forceFill([
                    'provider_credential_source' => 'integration',
                    'provider_integration_id' => $lockedConnection->getKey(),
                    'provider_binding_version' => $previousBindingVersion + 1,
                    'provider_bound_at' => now(),
                    'provider_bound_by' => $actor->id,
                ])->save();
                $account = $account->fresh();

                EmailProviderMigrationItem::query()->create([
                    'migration_run_id' => $cutover->id,
                    'email_account_id' => $account->id,
                    'provider_integration_id' => $lockedConnection->getKey(),
                    'credential_version_id' => $credential->id,
                    'status' => 'cutover',
                    'legacy_fingerprint' => $lockedItem->legacy_fingerprint,
                    'binding_fingerprint' => $this->legacy->bindingFingerprint($account),
                    'previous_source' => 'legacy',
                    'previous_provider_integration_id' => null,
                    'previous_binding_version' => $previousBindingVersion,
                    'staged_configuration_version' => (int) $lockedConnection->configuration_version,
                    'staged_credential_version' => (int) $credential->version,
                    'staged_at' => $credential->staged_at,
                    'verified_at' => $credential->verified_at,
                    'cutover_at' => now(),
                ]);

                $lockedItem->forceFill(['status' => 'rebound', 'block_code' => null, 'cutover_at' => now()])->save();
                $run->forceFill([
                    'status' => 'superseded',
                    'applied_by' => $actor->id,
                    'applied_count' => 1,
                    'finished_at' => now(),
                ])->save();

                $this->events->record(
                    $lockedConnection,
                    $actor,
                    'account_bound',
                    'verified_replacement_cutover',
                    (int) $credential->version,
                    'account-replacement:'.$account->id.':'.$account->provider_binding_version,
                );

                return $cutover->fresh('items');
            }, 3);
        } finally {
            $providerLock?->release();
            $idleBarrier?->release();
            $this->lifecycleLocks->release($lifecycleLocks);
        }
    }
}
