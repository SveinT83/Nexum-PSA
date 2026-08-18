<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use App\Modules\Integration\Models\EmailProviderMigrationRun;
use App\Modules\Integration\Services\EmailProviderCutoverReadiness;
use App\Modules\Integration\Services\EmailProviderEventRecorder;
use App\Modules\Integration\Services\EmailProviderLegacyAccountMaterial;
use App\Modules\Integration\Services\EmailProviderLifecycleAccountLocks;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderMigrationScope;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\DB;

final class ApplyEmailProviderCutover
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderMigrationScope $scope,
        private readonly EmailProviderLegacyAccountMaterial $legacy,
        private readonly EmailProviderCutoverReadiness $readiness,
        private readonly EmailProviderEventRecorder $events,
        private readonly EmailProviderLifecycleAccountLocks $lifecycleLocks,
    ) {}

    public function execute(User $actor, EmailProviderMigrationRun $preview): EmailProviderMigrationRun
    {
        $actor = $this->authorization->authorizeBinding($actor);
        $preview = EmailProviderMigrationRun::query()->with('items')->findOrFail($preview->id);
        $accountIds = $preview->items->sortBy('email_account_id')->pluck('email_account_id')
            ->map(fn (mixed $id): int => (int) $id)->values()->all();

        $connectionIds = $preview->items->pluck('provider_integration_id')->filter()->unique()
            ->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();
        foreach ($connectionIds as $connectionId) {
            $this->authorization->authorizeConnectionTrust(
                $actor,
                EmailProviderConnection::query()->findOrFail($connectionId),
            );
        }
        $providerLifecycleLocks = $this->acquireLifecycleLocks($connectionIds);
        $locks = [];

        try {
            $locks = $this->acquireProviderLocks($accountIds);

            return DB::transaction(function () use ($actor, $preview, $accountIds): EmailProviderMigrationRun {
                $run = EmailProviderMigrationRun::query()->lockForUpdate()->findOrFail($preview->id);
                $items = EmailProviderMigrationItem::query()
                    ->where('migration_run_id', $run->id)
                    ->orderBy('email_account_id')
                    ->lockForUpdate()
                    ->get();

                if ($run->operation !== 'cutover'
                    || $run->status !== 'previewed'
                    || $run->preview_expires_at->isPast()
                    || (int) $run->blocked_count !== 0
                    || $items->count() !== (int) $run->account_count
                    || ! hash_equals((string) $run->scope_fingerprint, $this->scope->fingerprint($accountIds))) {
                    throw new EmailProviderSecurityException('cutover_preview_not_applicable');
                }

                foreach ($items as $item) {
                    $account = EmailAccount::query()->lockForUpdate()->findOrFail($item->email_account_id);

                    if (! hash_equals((string) $item->binding_fingerprint, $this->legacy->bindingFingerprint($account))) {
                        throw new EmailProviderSecurityException('cutover_binding_stale');
                    }

                    $blockCode = $this->readiness->blockCode($account, $item);
                    if ($blockCode !== null) {
                        throw new EmailProviderSecurityException('cutover_not_ready');
                    }

                    $account->forceFill([
                        'provider_credential_source' => 'integration',
                        'provider_integration_id' => $item->provider_integration_id,
                        'provider_binding_version' => (int) $account->provider_binding_version + 1,
                        'provider_bound_at' => now(),
                        'provider_bound_by' => $actor->id,
                    ])->save();
                    $account = $account->fresh();
                    $item->forceFill([
                        'status' => 'cutover',
                        'block_code' => null,
                        'binding_fingerprint' => $this->legacy->bindingFingerprint($account),
                        'cutover_at' => now(),
                    ])->save();

                    $connection = EmailProviderConnection::query()->findOrFail($item->provider_integration_id);
                    $this->events->record(
                        $connection,
                        $actor,
                        'account_bound',
                        'verified_cutover',
                        (int) $item->staged_credential_version,
                        'account-bound:'.$account->id.':'.$account->provider_binding_version,
                    );
                }

                $run->forceFill([
                    'status' => 'applied',
                    'applied_by' => $actor->id,
                    'applied_count' => $items->count(),
                    'started_at' => now(),
                    'finished_at' => now(),
                    'rollback_deadline_at' => now()->addDays(7),
                ])->save();

                return $run->fresh('items');
            }, 3);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
            $this->lifecycleLocks->release($providerLifecycleLocks);
        }
    }

    /** @param list<int> $accountIds
     * @return list<Lock>
     */
    private function acquireProviderLocks(array $accountIds): array
    {
        $locks = [];

        foreach ($accountIds as $accountId) {
            $lock = EmailAccountProviderLock::acquire($accountId, 60);
            if (! $lock) {
                foreach (array_reverse($locks) as $acquired) {
                    $acquired->release();
                }

                throw new EmailProviderSecurityException('provider_work_not_drained');
            }

            $locks[] = $lock;
        }

        return $locks;
    }

    /** @param list<string> $connectionIds
     * @return list<Lock>
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
