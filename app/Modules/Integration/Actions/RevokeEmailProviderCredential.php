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

final class RevokeEmailProviderCredential
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
        string $reasonCode,
    ): EmailProviderCredentialVersion {
        $connection = EmailProviderConnection::query()->findOrFail($connection->getKey());
        $actor = $this->authorization->authorizeConnectionTrust($actor, $connection);

        $locks = $this->accountLocks->acquire($connection->getKey());

        try {
            return DB::transaction(function () use ($actor, $connection, $version, $reasonCode): EmailProviderCredentialVersion {
                $connection = EmailProviderConnection::query()->lockForUpdate()->findOrFail($connection->getKey());
                $version = EmailProviderCredentialVersion::query()->lockForUpdate()->findOrFail($version->id);

                if ($version->provider_integration_id !== $connection->getKey()
                    || ! in_array($version->state, [
                        EmailProviderCredentialVersion::STATE_STAGED,
                        EmailProviderCredentialVersion::STATE_ACTIVE,
                    ], true)) {
                    throw new EmailProviderSecurityException('credential_not_revocable');
                }

                $wasActive = (int) $connection->active_credential_version_id === (int) $version->id;
                $version->forceFill([
                    'state' => EmailProviderCredentialVersion::STATE_REVOKED,
                    'revoked_by' => $actor->id,
                    'revoked_at' => now(),
                    'destroyed_by' => $actor->id,
                    'destroyed_at' => now(),
                    ...$this->cipher->destroyedCiphertext(),
                ])->save();

                if ($wasActive) {
                    $connection->forceFill([
                        'status' => 'revoked',
                        'active_credential_version_id' => null,
                        'updated_by' => $actor->id,
                        'lock_version' => (int) $connection->lock_version + 1,
                    ])->save();
                    Integration::query()->whereKey($connection->getKey())->update([
                        'status' => 'disabled',
                        'is_healthy' => false,
                        'last_error' => null,
                        'config' => json_encode(['provider_status' => 'revoked']),
                        'updated_at' => now(),
                    ]);
                }

                if ((int) $connection->verification_claim_credential_version === (int) $version->version) {
                    $connection->forceFill([
                        'verification_claim_token' => null,
                        'verification_claim_configuration_version' => null,
                        'verification_claim_credential_version' => null,
                        'verification_claim_expires_at' => null,
                    ])->save();
                }

                $this->events->record(
                    $connection,
                    $actor,
                    'credential_revoked',
                    $reasonCode,
                    (int) $version->version,
                );

                return $version->fresh();
            });
        } finally {
            $this->accountLocks->release($locks);
        }
    }
}
