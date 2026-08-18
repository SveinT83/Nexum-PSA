<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use App\Modules\Integration\Models\EmailProviderMigrationRun;
use App\Modules\Integration\Services\EmailProviderAuthenticationPolicy;
use App\Modules\Integration\Services\EmailProviderLegacyAccountMaterial;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderMigrationScope;
use Illuminate\Support\Facades\DB;

final class StageLegacyEmailProviderMigration
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderMigrationScope $scope,
        private readonly EmailProviderLegacyAccountMaterial $legacy,
        private readonly EmailProviderAuthenticationPolicy $authentication,
        private readonly CreateEmailProviderConnection $createConnection,
    ) {}

    /**
     * @param  array<int|string, array{trust_mode?:string,trusted_cidr_name?:string,private_endpoint_reason?:string}>  $endpointTrustByAccount
     */
    public function execute(
        #[\SensitiveParameter] User $actor,
        #[\SensitiveParameter] EmailProviderMigrationRun $preview,
        #[\SensitiveParameter] array $endpointTrustByAccount = [],
    ): EmailProviderMigrationRun {
        $actor = $this->authorization->authorizeProvider($actor, true);

        return DB::transaction(function () use ($actor, $preview, $endpointTrustByAccount): EmailProviderMigrationRun {
            $run = EmailProviderMigrationRun::query()->lockForUpdate()->findOrFail($preview->id);
            $items = EmailProviderMigrationItem::query()
                ->where('migration_run_id', $run->id)
                ->orderBy('email_account_id')
                ->lockForUpdate()
                ->get();

            if ($run->operation !== 'legacy_migration') {
                throw new EmailProviderSecurityException('migration_run_type_invalid');
            }

            if ($run->status === 'staged') {
                return $run->fresh('items');
            }

            if ($run->status !== 'previewed'
                || $run->preview_expires_at->isPast()
                || (int) $run->blocked_count !== 0
                || $items->count() !== (int) $run->account_count) {
                throw new EmailProviderSecurityException('migration_preview_not_stageable');
            }

            $ids = $items->pluck('email_account_id')->map(fn (mixed $id): int => (int) $id)->all();
            if (! hash_equals((string) $run->scope_fingerprint, $this->scope->fingerprint($ids))) {
                throw new EmailProviderSecurityException('migration_scope_stale');
            }
            $trustByAccount = $this->normalizeTrustInput($endpointTrustByAccount, $ids);

            foreach ($items as $item) {
                $account = EmailAccount::query()->lockForUpdate()->findOrFail($item->email_account_id);

                if ((string) ($account->provider_credential_source ?: 'legacy') !== 'legacy'
                    || filled($account->provider_integration_id)
                    || ! hash_equals((string) $item->legacy_fingerprint, $this->legacy->legacyFingerprint($account))) {
                    throw new EmailProviderSecurityException('legacy_snapshot_stale');
                }

                $material = $this->legacy->decrypt($account);
                $trust = $trustByAccount[$account->id];

                try {
                    $connection = $this->createConnection->execute($actor, [
                        'name' => 'Mailbox provider '.$account->id,
                        'imap_host' => (string) $account->imap_host,
                        'imap_port' => (int) $account->imap_port,
                        'imap_transport' => (string) $account->imap_encryption,
                        'imap_auth_type' => $this->authentication->normalizeLegacy(
                            'imap',
                            (string) $account->imap_auth_type,
                        ),
                        'imap_username' => $material['imap_username'],
                        'imap_secret' => $material['imap_secret'],
                        'smtp_host' => (string) $account->smtp_host,
                        'smtp_port' => (int) $account->smtp_port,
                        'smtp_transport' => (string) $account->smtp_encryption,
                        'smtp_auth_type' => $this->authentication->normalizeLegacy(
                            'smtp',
                            (string) $account->smtp_auth_type,
                        ),
                        'smtp_username' => $material['smtp_username'],
                        'smtp_secret' => $material['smtp_secret'],
                        ...$trust,
                    ]);
                    $credential = $connection->credentialVersions()->where('version', 1)->firstOrFail();

                    $item->forceFill([
                        'provider_integration_id' => $connection->getKey(),
                        'credential_version_id' => $credential->id,
                        'status' => 'staged',
                        'block_code' => null,
                        'staged_configuration_version' => (int) $connection->configuration_version,
                        'staged_credential_version' => (int) $credential->version,
                        'staged_at' => now(),
                    ])->save();
                } finally {
                    foreach (array_keys($material) as $key) {
                        if (is_string($material[$key]) && function_exists('sodium_memzero')) {
                            sodium_memzero($material[$key]);
                        }
                    }
                    unset($material);
                }
            }

            $run->forceFill([
                'status' => 'staged',
                'started_at' => now(),
                'finished_at' => now(),
                'applied_by' => $actor->id,
                'applied_count' => $items->count(),
            ])->save();

            return $run->fresh('items');
        }, 3);
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @param  list<int>  $accountIds
     * @return array<int, array{trust_mode:string,trusted_cidr_name?:string,private_endpoint_reason?:string}>
     */
    private function normalizeTrustInput(
        #[\SensitiveParameter] array $input,
        array $accountIds,
    ): array {
        $normalized = [];

        foreach ($input as $accountId => $trust) {
            if (filter_var($accountId, FILTER_VALIDATE_INT) === false
                || ! in_array((int) $accountId, $accountIds, true)
                || ! is_array($trust)) {
                throw new EmailProviderSecurityException('migration_trust_scope_invalid');
            }

            $mode = (string) ($trust['trust_mode'] ?? 'public');
            if ($mode === 'public') {
                $normalized[(int) $accountId] = ['trust_mode' => 'public'];

                continue;
            }

            if ($mode !== 'trusted_private') {
                throw new EmailProviderSecurityException('migration_trust_mode_invalid');
            }

            $normalized[(int) $accountId] = [
                'trust_mode' => 'trusted_private',
                'trusted_cidr_name' => trim((string) ($trust['trusted_cidr_name'] ?? '')),
                'private_endpoint_reason' => trim((string) ($trust['private_endpoint_reason'] ?? '')),
            ];
        }

        foreach ($accountIds as $accountId) {
            $normalized[$accountId] ??= ['trust_mode' => 'public'];
        }

        ksort($normalized);

        return $normalized;
    }
}
