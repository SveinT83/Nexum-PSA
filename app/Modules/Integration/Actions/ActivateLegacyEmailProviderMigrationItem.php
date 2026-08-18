<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use Illuminate\Support\Facades\DB;

final class ActivateLegacyEmailProviderMigrationItem
{
    public function __construct(
        private readonly ActivateEmailProviderCredential $activateCredential,
        private readonly EmailProviderManagementAuthorization $authorization,
    ) {}

    public function execute(User $actor, EmailProviderMigrationItem $item): EmailProviderMigrationItem
    {
        // Authenticate before loading or branching so terminal/idempotent rows
        // do not become an enumeration or mutation bypass.
        $actor = $this->authorization->authorizeProvider($actor, true);
        $item = EmailProviderMigrationItem::query()->findOrFail($item->id);

        if (! in_array($item->status, ['verified', 'active'], true)
            || ! $item->provider_integration_id
            || ! $item->credential_version_id) {
            throw new EmailProviderSecurityException('migration_item_not_activatable');
        }

        $connection = EmailProviderConnection::query()->findOrFail($item->provider_integration_id);
        $actor = $this->authorization->authorizeConnectionTrust($actor, $connection);
        $credential = EmailProviderCredentialVersion::query()->findOrFail($item->credential_version_id);

        if ($credential->state === EmailProviderCredentialVersion::STATE_ACTIVE
            && (int) $connection->active_credential_version_id === (int) $credential->id
            && (int) $connection->verified_configuration_version === (int) $connection->configuration_version
            && (int) $connection->verified_credential_version === (int) $credential->version) {
            if ($item->status !== 'active') {
                $item->forceFill(['status' => 'active'])->save();
            }

            return $item->fresh();
        }

        if ($item->status !== 'verified') {
            throw new EmailProviderSecurityException('migration_activation_snapshot_stale');
        }

        $this->activateCredential->execute($actor, $connection, $credential);

        return DB::transaction(function () use ($item): EmailProviderMigrationItem {
            $locked = EmailProviderMigrationItem::query()->lockForUpdate()->findOrFail($item->id);
            $locked->forceFill(['status' => 'active'])->save();

            return $locked->fresh();
        }, 3);
    }
}
