<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Services\EmailProviderCredentialCipher;
use App\Modules\Integration\Services\EmailProviderEventRecorder;
use App\Modules\Integration\Services\EmailProviderLifecycleAccountLocks;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use Illuminate\Support\Facades\DB;

final class ActivateEmailProviderCredential
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderCredentialCipher $cipher,
        private readonly EmailProviderEventRecorder $events,
        private readonly EmailProviderLifecycleAccountLocks $accountLocks,
    ) {}

    public function execute(
        #[\SensitiveParameter] User $actor,
        #[\SensitiveParameter] EmailProviderConnection $connection,
        #[\SensitiveParameter] EmailProviderCredentialVersion $version,
    ): EmailProviderCredentialVersion {
        $connection = EmailProviderConnection::query()->findOrFail($connection->getKey());
        $actor = $this->authorization->authorizeConnectionTrust($actor, $connection);

        $locks = $this->accountLocks->acquire($connection->getKey());

        try {
            return DB::transaction(function () use ($actor, $connection, $version): EmailProviderCredentialVersion {
                $connection = EmailProviderConnection::query()->lockForUpdate()->findOrFail($connection->getKey());
                $version = EmailProviderCredentialVersion::query()->lockForUpdate()->findOrFail($version->id);

                if ($version->provider_integration_id === $connection->getKey()
                    && $version->state === EmailProviderCredentialVersion::STATE_ACTIVE
                    && (int) $connection->active_credential_version_id === (int) $version->id
                    && (int) $connection->verified_configuration_version === (int) $connection->configuration_version
                    && (int) $connection->verified_credential_version === (int) $version->version) {
                    return $version;
                }

                if ($version->provider_integration_id !== $connection->getKey()
                    || $version->state !== EmailProviderCredentialVersion::STATE_STAGED
                    || ! $version->verified_at
                    || (int) $version->verified_configuration_version !== (int) $connection->configuration_version) {
                    throw new EmailProviderSecurityException('credential_activation_not_verified');
                }

                if ($connection->active_credential_version_id) {
                    $previous = EmailProviderCredentialVersion::query()
                        ->lockForUpdate()
                        ->findOrFail($connection->active_credential_version_id);

                    if ($previous->state !== EmailProviderCredentialVersion::STATE_ACTIVE) {
                        throw new EmailProviderSecurityException('active_credential_state_invalid');
                    }

                    $this->assertSameCredentialIdentity($previous, $version);

                    $previous->forceFill([
                        'state' => EmailProviderCredentialVersion::STATE_DESTROYED,
                        'retired_at' => now(),
                        'destroyed_at' => now(),
                        'destroyed_by' => $actor->id,
                        ...$this->cipher->destroyedCiphertext(),
                    ])->save();
                    $this->events->record(
                        $connection,
                        $actor,
                        'credential_retired_destroyed',
                        'superseded_by_verified_rotation',
                        (int) $previous->version,
                    );
                }

                $version->forceFill([
                    'state' => EmailProviderCredentialVersion::STATE_ACTIVE,
                    'activated_by' => $actor->id,
                    'activated_at' => now(),
                ])->save();
                $connection->forceFill([
                    'status' => 'active',
                    'active_credential_version_id' => $version->id,
                    'verified_configuration_version' => $connection->configuration_version,
                    'verified_credential_version' => $version->version,
                    'updated_by' => $actor->id,
                    'lock_version' => (int) $connection->lock_version + 1,
                ])->save();
                Integration::query()->whereKey($connection->getKey())->update([
                    'status' => 'active',
                    'is_healthy' => true,
                    'last_error' => null,
                    'config' => json_encode(['provider_status' => 'active']),
                    'updated_at' => now(),
                ]);
                $this->events->record(
                    $connection,
                    $actor,
                    'credential_activated',
                    'exact_verified_version',
                    (int) $version->version,
                );

                return $version->fresh();
            });
        } finally {
            $this->accountLocks->release($locks);
        }
    }

    private function assertSameCredentialIdentity(
        #[\SensitiveParameter] EmailProviderCredentialVersion $active,
        #[\SensitiveParameter] EmailProviderCredentialVersion $staged,
    ): void {
        $activeMaterial = $this->cipher->decrypt($active);
        $stagedMaterial = $this->cipher->decrypt($staged);

        try {
            if (! hash_equals($activeMaterial['imap_username'], $stagedMaterial['imap_username'])
                || ! hash_equals($activeMaterial['smtp_username'], $stagedMaterial['smtp_username'])) {
                throw new EmailProviderSecurityException('credential_identity_change_requires_new_connection');
            }
        } finally {
            foreach ([$activeMaterial, $stagedMaterial] as &$material) {
                foreach ($material as &$value) {
                    if (is_string($value) && function_exists('sodium_memzero')) {
                        sodium_memzero($value);
                    }
                }
                unset($value);
            }
            unset($material, $activeMaterial, $stagedMaterial);
        }
    }
}
