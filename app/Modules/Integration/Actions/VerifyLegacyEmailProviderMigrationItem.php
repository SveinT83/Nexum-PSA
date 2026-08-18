<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;

final class VerifyLegacyEmailProviderMigrationItem
{
    public function __construct(
        private readonly VerifyEmailProviderCredential $verifyCredential,
        private readonly EmailProviderManagementAuthorization $authorization,
    ) {}

    /**
     * This is the only migration action that performs provider I/O. Activation
     * remains a separate local action after this exact version succeeds.
     */
    public function execute(User $actor, EmailProviderMigrationItem $item): EmailProviderMigrationItem
    {
        // Authenticate before loading or branching so terminal/idempotent rows
        // do not become an enumeration or mutation bypass.
        $actor = $this->authorization->authorizeProvider($actor, true);
        $item = EmailProviderMigrationItem::query()->findOrFail($item->id);

        if (! in_array($item->status, ['staged', 'verified', 'active'], true)
            || ! $item->provider_integration_id
            || ! $item->credential_version_id) {
            throw new EmailProviderSecurityException('migration_item_not_verifiable');
        }

        $connection = EmailProviderConnection::query()->findOrFail($item->provider_integration_id);
        $actor = $this->authorization->authorizeConnectionTrust($actor, $connection);
        $credential = EmailProviderCredentialVersion::query()->findOrFail($item->credential_version_id);

        if ($credential->verified_at
            && (int) $credential->verified_configuration_version === (int) $connection->configuration_version) {
            if ($item->status === 'staged') {
                $item->forceFill([
                    'status' => 'verified',
                    'verified_at' => $credential->verified_at,
                ])->save();
            }

            return $item->fresh();
        }

        if ($item->status !== 'staged') {
            throw new EmailProviderSecurityException('migration_verification_snapshot_stale');
        }

        $verified = $this->verifyCredential->execute($actor, $connection, $credential);

        $item->forceFill([
            'status' => 'verified',
            'verified_at' => $verified->verified_at,
        ])->save();

        return $item->fresh();
    }
}
