<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailRemoteOperation;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RollbackEmailProviderCutover
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderMigrationScope $scope,
        private readonly EmailProviderLegacyAccountMaterial $legacy,
        private readonly EmailProviderEventRecorder $events,
        private readonly EmailProviderLifecycleAccountLocks $lifecycleLocks,
        private readonly EmailProviderCutoverReadiness $readiness,
    ) {}

    public function execute(User $actor, EmailProviderMigrationRun $cutover): EmailProviderMigrationRun
    {
        $actor = $this->authorization->authorizeBinding($actor);
        $cutover = EmailProviderMigrationRun::query()->with('items')->findOrFail($cutover->id);
        $accountIds = $cutover->items->sortBy('email_account_id')->pluck('email_account_id')
            ->map(fn (mixed $id): int => (int) $id)->values()->all();

        $connectionIds = $cutover->items->pluck('provider_integration_id')->filter()->unique()
            ->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();
        foreach ($connectionIds as $connectionId) {
            $this->authorization->authorizeConnectionTrust(
                $actor,
                EmailProviderConnection::query()->findOrFail($connectionId),
            );
        }
        $locks = $this->acquireLifecycleLocks($connectionIds);

        try {
            return DB::transaction(function () use ($actor, $cutover, $accountIds): EmailProviderMigrationRun {
                $source = EmailProviderMigrationRun::query()->lockForUpdate()->findOrFail($cutover->id);
                $sourceItems = EmailProviderMigrationItem::query()
                    ->where('migration_run_id', $source->id)
                    ->orderBy('email_account_id')
                    ->lockForUpdate()
                    ->get();

                if ($source->operation !== 'cutover'
                    || $source->status !== 'applied'
                    || ! $source->rollback_deadline_at
                    || $source->rollback_deadline_at->isPast()
                    || $sourceItems->count() !== (int) $source->account_count
                    || ! hash_equals((string) $source->scope_fingerprint, $this->scope->fingerprint($accountIds))) {
                    throw new EmailProviderSecurityException('cutover_not_rollbackable');
                }

                $rollback = EmailProviderMigrationRun::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'operation' => 'rollback',
                    'status' => 'running',
                    'scope_fingerprint' => $source->scope_fingerprint,
                    'account_count' => $source->account_count,
                    'ready_count' => $source->account_count,
                    'created_by' => $actor->id,
                    'applied_by' => $actor->id,
                    'rollback_of_run_id' => $source->id,
                    'source_run_id' => $source->source_run_id,
                    'preview_expires_at' => now(),
                    'started_at' => now(),
                ]);

                foreach ($sourceItems as $item) {
                    $account = EmailAccount::query()->lockForUpdate()->findOrFail($item->email_account_id);
                    $this->assertRollbackReady($account, $item, $source);

                    $account->forceFill([
                        'provider_credential_source' => 'legacy',
                        'provider_integration_id' => null,
                        'provider_binding_version' => (int) $account->provider_binding_version + 1,
                        'provider_bound_at' => now(),
                        'provider_bound_by' => $actor->id,
                    ])->save();
                    $item->forceFill(['status' => 'rolled_back', 'rolled_back_at' => now()])->save();

                    EmailProviderMigrationItem::query()->create([
                        'migration_run_id' => $rollback->id,
                        'email_account_id' => $account->id,
                        'provider_integration_id' => $item->provider_integration_id,
                        'credential_version_id' => $item->credential_version_id,
                        'status' => 'rolled_back',
                        'legacy_fingerprint' => $item->legacy_fingerprint,
                        'binding_fingerprint' => $this->legacy->bindingFingerprint($account->fresh()),
                        'previous_source' => 'integration',
                        'previous_provider_integration_id' => $item->provider_integration_id,
                        'previous_binding_version' => (int) $account->provider_binding_version - 1,
                        'staged_configuration_version' => $item->staged_configuration_version,
                        'staged_credential_version' => $item->staged_credential_version,
                        'staged_at' => $item->staged_at,
                        'verified_at' => $item->verified_at,
                        'cutover_at' => $item->cutover_at,
                        'rolled_back_at' => now(),
                    ]);

                    $connection = EmailProviderConnection::query()->findOrFail($item->provider_integration_id);
                    $this->events->record(
                        $connection,
                        $actor,
                        'account_binding_rolled_back',
                        'legacy_source_restored',
                        (int) $item->staged_credential_version,
                        'account-rollback:'.$account->id.':'.$account->provider_binding_version,
                    );
                }

                $source->forceFill([
                    'status' => 'rolled_back',
                    'rolled_back_by' => $actor->id,
                    'rolled_back_at' => now(),
                ])->save();
                $rollback->forceFill([
                    'status' => 'applied',
                    'applied_count' => $sourceItems->count(),
                    'finished_at' => now(),
                    'rolled_back_by' => $actor->id,
                    'rolled_back_at' => now(),
                ])->save();

                return $rollback->fresh('items');
            }, 3);
        } finally {
            $this->lifecycleLocks->release($locks);
        }
    }

    private function assertRollbackReady(
        EmailAccount $account,
        EmailProviderMigrationItem $item,
        EmailProviderMigrationRun $source,
    ): void {
        if ((string) $account->provider_credential_source !== 'integration'
            || (string) $account->provider_integration_id !== (string) $item->provider_integration_id
            || ! hash_equals((string) $item->binding_fingerprint, $this->legacy->bindingFingerprint($account))
            || ! hash_equals((string) $item->legacy_fingerprint, $this->legacy->legacyFingerprint($account))) {
            throw new EmailProviderSecurityException('rollback_binding_or_legacy_stale');
        }

        if (! $account->provider_runtime_paused_at
            || ! $account->provider_runtime_drained_at
            || Carbon::parse((string) $account->provider_runtime_drained_at)
                ->lt(Carbon::parse((string) $account->provider_runtime_paused_at))) {
            throw new EmailProviderSecurityException('rollback_work_not_paused_drained');
        }

        if (EmailRemoteOperation::query()
            ->where('account_id', $account->id)
            ->whereIn('status', [
                EmailRemoteOperation::STATUS_PENDING,
                EmailRemoteOperation::STATUS_RUNNING,
                EmailRemoteOperation::STATUS_FAILED,
            ])->exists()) {
            throw new EmailProviderSecurityException('rollback_provider_operation_unresolved');
        }

        if ($this->readiness->hasUnresolvedProviderWork($account)) {
            throw new EmailProviderSecurityException('rollback_provider_binding_work_unresolved');
        }

        $connection = EmailProviderConnection::query()->with('activeCredentialVersion')
            ->find($item->provider_integration_id);
        $credential = $connection?->activeCredentialVersion;

        if (! $connection
            || ! $credential
            || (int) $credential->id !== (int) $item->credential_version_id
            || $credential->state !== EmailProviderCredentialVersion::STATE_ACTIVE
            || ! $credential->hasCiphertext()
            || (int) $connection->configuration_version !== (int) $item->staged_configuration_version
            || (int) $connection->verified_configuration_version !== (int) $item->staged_configuration_version
            || (int) $connection->verified_credential_version !== (int) $item->staged_credential_version
            || (int) $credential->version !== (int) $item->staged_credential_version
            || $source->rollback_deadline_at->isPast()) {
            throw new EmailProviderSecurityException('rollback_provider_lifecycle_changed');
        }
    }

    /** @param list<string> $connectionIds
     * @return list<\Illuminate\Contracts\Cache\Lock>
     */
    private function acquireLifecycleLocks(array $connectionIds): array
    {
        $locks = [];

        try {
            foreach ($connectionIds as $connectionId) {
                array_push($locks, ...$this->lifecycleLocks->acquire($connectionId));
            }

            return $locks;
        } catch (\Throwable $exception) {
            $this->lifecycleLocks->release($locks);

            throw $exception;
        }
    }
}
