<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use App\Modules\Integration\Models\EmailProviderMigrationRun;
use App\Modules\Integration\Services\EmailProviderCutoverReadiness;
use App\Modules\Integration\Services\EmailProviderLegacyAccountMaterial;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderMigrationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PreviewEmailProviderCutover
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderMigrationScope $scope,
        private readonly EmailProviderLegacyAccountMaterial $legacy,
        private readonly EmailProviderCutoverReadiness $readiness,
    ) {}

    public function execute(User $actor, EmailProviderMigrationRun $stagingRun): EmailProviderMigrationRun
    {
        $actor = $this->authorization->authorizeBinding($actor);
        $stagingRun = EmailProviderMigrationRun::query()->with('items')->findOrFail($stagingRun->id);

        if ($stagingRun->operation !== 'legacy_migration' || $stagingRun->status !== 'staged') {
            throw new EmailProviderSecurityException('staging_run_not_ready');
        }

        $stagedItems = $stagingRun->items->sortBy('email_account_id')->values();
        $accountIds = $stagedItems->pluck('email_account_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ($stagedItems->pluck('provider_integration_id')->filter()->unique() as $connectionId) {
            $this->authorization->authorizeConnectionTrust(
                $actor,
                \App\Modules\Integration\Models\EmailProviderConnection::query()->findOrFail($connectionId),
            );
        }

        if ($stagedItems->count() !== (int) $stagingRun->account_count
            || ! hash_equals((string) $stagingRun->scope_fingerprint, $this->scope->fingerprint($accountIds))) {
            throw new EmailProviderSecurityException('staging_scope_stale');
        }

        return DB::transaction(function () use ($actor, $stagingRun, $stagedItems, $accountIds): EmailProviderMigrationRun {
            $run = EmailProviderMigrationRun::query()->create([
                'public_id' => (string) Str::uuid(),
                'operation' => 'cutover',
                'status' => 'previewed',
                'scope_fingerprint' => $this->scope->fingerprint($accountIds),
                'account_count' => count($accountIds),
                'created_by' => $actor->id,
                'source_run_id' => $stagingRun->id,
                'preview_expires_at' => now()->addMinutes(15),
            ]);
            $ready = 0;
            $blocked = 0;

            foreach ($stagedItems as $stagedItem) {
                $account = EmailAccount::query()->findOrFail($stagedItem->email_account_id);
                if ((int) $account->provider_binding_version < 1) {
                    throw new EmailProviderSecurityException('provider_binding_invalid');
                }
                $blockCode = $this->readiness->blockCode($account, $stagedItem);
                $blockCode === null ? $ready++ : $blocked++;

                EmailProviderMigrationItem::query()->create([
                    'migration_run_id' => $run->id,
                    'email_account_id' => $account->id,
                    'provider_integration_id' => $stagedItem->provider_integration_id,
                    'credential_version_id' => $stagedItem->credential_version_id,
                    'status' => $blockCode === null ? 'ready' : 'blocked',
                    'block_code' => $blockCode,
                    'legacy_fingerprint' => $stagedItem->legacy_fingerprint,
                    'binding_fingerprint' => $this->legacy->bindingFingerprint($account),
                    'previous_source' => (string) ($account->provider_credential_source ?: 'legacy'),
                    'previous_provider_integration_id' => $account->provider_integration_id,
                    'previous_binding_version' => (int) $account->provider_binding_version,
                    'staged_configuration_version' => $stagedItem->staged_configuration_version,
                    'staged_credential_version' => $stagedItem->staged_credential_version,
                    'staged_at' => $stagedItem->staged_at,
                    'verified_at' => $stagedItem->verified_at,
                ]);
            }

            $run->forceFill(['ready_count' => $ready, 'blocked_count' => $blocked])->save();

            return $run->fresh('items');
        }, 3);
    }
}
