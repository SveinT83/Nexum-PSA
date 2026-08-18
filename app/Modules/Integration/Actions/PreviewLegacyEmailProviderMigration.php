<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use App\Modules\Integration\Models\EmailProviderMigrationRun;
use App\Modules\Integration\Services\EmailProviderAuthenticationPolicy;
use App\Modules\Integration\Services\EmailProviderEndpointPolicy;
use App\Modules\Integration\Services\EmailProviderLegacyAccountMaterial;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderMigrationScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PreviewLegacyEmailProviderMigration
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderMigrationScope $scope,
        private readonly EmailProviderLegacyAccountMaterial $legacy,
        private readonly EmailProviderEndpointPolicy $endpoints,
        private readonly EmailProviderAuthenticationPolicy $authentication,
    ) {}

    /** @param array<int, mixed> $accountIds */
    public function execute(User $actor, array $accountIds): EmailProviderMigrationRun
    {
        $actor = $this->authorization->authorizeProvider($actor, true);
        $accountIds = $this->scope->normalize($accountIds);
        $accounts = EmailAccount::query()->whereKey($accountIds)->orderBy('id')->get()->keyBy('id');

        if ($accounts->count() !== count($accountIds)) {
            throw new EmailProviderSecurityException('migration_scope_not_found');
        }

        if ($accounts->contains(fn (EmailAccount $account): bool => (int) $account->provider_binding_version < 1)) {
            throw new EmailProviderSecurityException('provider_binding_invalid');
        }

        return DB::transaction(function () use ($actor, $accountIds, $accounts): EmailProviderMigrationRun {
            $run = EmailProviderMigrationRun::query()->create([
                'public_id' => (string) Str::uuid(),
                'operation' => 'legacy_migration',
                'status' => 'previewed',
                'scope_fingerprint' => $this->scope->fingerprint($accountIds),
                'account_count' => count($accountIds),
                'created_by' => $actor->id,
                'preview_expires_at' => now()->addMinutes(15),
            ]);
            $ready = 0;
            $blocked = 0;

            foreach ($accountIds as $accountId) {
                /** @var EmailAccount $account */
                $account = $accounts->get($accountId);
                $blockCode = $this->previewBlockCode($account);
                $blockCode === null ? $ready++ : $blocked++;

                EmailProviderMigrationItem::query()->create([
                    'migration_run_id' => $run->id,
                    'email_account_id' => $account->id,
                    'status' => $blockCode === null ? 'ready' : 'blocked',
                    'block_code' => $blockCode,
                    'legacy_fingerprint' => $this->legacy->legacyFingerprint($account),
                    'previous_source' => (string) ($account->provider_credential_source ?: 'legacy'),
                    'previous_provider_integration_id' => $account->provider_integration_id,
                    'previous_binding_version' => (int) $account->provider_binding_version,
                ]);
            }

            $run->forceFill(['ready_count' => $ready, 'blocked_count' => $blocked])->save();

            return $run->fresh('items');
        }, 3);
    }

    private function previewBlockCode(EmailAccount $account): ?string
    {
        if ((string) ($account->provider_credential_source ?: 'legacy') !== 'legacy'
            || filled($account->provider_integration_id)) {
            return 'account_source_not_legacy';
        }

        if (! $this->legacy->isComplete($account)) {
            return 'legacy_material_incomplete';
        }

        try {
            $this->endpoints->normalize('imap', (string) $account->imap_host, (int) $account->imap_port, (string) $account->imap_encryption);
            $this->endpoints->normalize('smtp', (string) $account->smtp_host, (int) $account->smtp_port, (string) $account->smtp_encryption);
            $this->authentication->normalizeLegacy('imap', (string) $account->imap_auth_type);
            $this->authentication->normalizeLegacy('smtp', (string) $account->smtp_auth_type);
        } catch (EmailProviderSecurityException) {
            return 'legacy_configuration_not_supported';
        }

        return null;
    }
}
