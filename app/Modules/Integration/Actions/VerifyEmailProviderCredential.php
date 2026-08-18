<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Services\EmailProviderConnectionVerifier;
use App\Modules\Integration\Services\EmailProviderEventRecorder;
use App\Modules\Integration\Services\EmailProviderLifecycleAccountLocks;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderRuntimeFactory;
use App\Modules\Integration\Services\EmailProviderVerificationDeadline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VerifyEmailProviderCredential
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderRuntimeFactory $runtimeFactory,
        private readonly EmailProviderConnectionVerifier $verifier,
        private readonly EmailProviderVerificationDeadline $deadline,
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
        // Acquire every bound-account barrier before taking a credential
        // snapshot. Revoke cannot destroy the version in the claim/socket gap,
        // and the barrier remains owned through verification finalization.
        $lifecycleLocks = $this->accountLocks->acquire((string) $connection->getKey());

        try {
            $claim = DB::transaction(function () use ($connection, $version, $actor): array {
                $lockedConnection = EmailProviderConnection::query()->lockForUpdate()->findOrFail($connection->getKey());
                $lockedVersion = EmailProviderCredentialVersion::query()->lockForUpdate()->findOrFail($version->id);

                if ($lockedVersion->provider_integration_id !== $lockedConnection->getKey()
                    || $lockedVersion->state !== EmailProviderCredentialVersion::STATE_STAGED) {
                    throw new EmailProviderSecurityException('credential_not_verifiable');
                }

                if ($lockedVersion->verified_at
                    && (int) $lockedVersion->verified_configuration_version === (int) $lockedConnection->configuration_version) {
                    if ((int) $lockedConnection->verification_claim_configuration_version === (int) $lockedConnection->configuration_version
                        && (int) $lockedConnection->verification_claim_credential_version === (int) $lockedVersion->version) {
                        $lockedConnection->forceFill($this->clearedClaim())->save();
                    }

                    return ['verified' => $lockedVersion->fresh()];
                }

                if ($lockedConnection->verification_claim_token
                    && $lockedConnection->verification_claim_expires_at
                    && $lockedConnection->verification_claim_expires_at->isFuture()) {
                    throw new EmailProviderSecurityException('verification_in_progress');
                }

                $token = (string) Str::uuid();
                $configurationVersion = (int) $lockedConnection->configuration_version;
                $credentialVersion = (int) $lockedVersion->version;
                $verificationDeadline = max(
                    2,
                    min(120, (int) config('email_provider_security.verification_deadline_seconds', 60)),
                );
                $minimumLease = $verificationDeadline + 30;
                $lockedConnection->forceFill([
                    'verification_claim_token' => $token,
                    'verification_claim_configuration_version' => $configurationVersion,
                    'verification_claim_credential_version' => $credentialVersion,
                    'verification_claim_expires_at' => now()->addSeconds(max(
                        $minimumLease,
                        min(600, (int) config('email_provider_security.verification_lease_seconds', 120)),
                    )),
                    'last_verification_code' => 'verification_in_progress',
                    'updated_by' => $actor->id,
                ])->save();

                return [
                    'token' => $token,
                    'configuration_version' => $configurationVersion,
                    'credential_version' => $credentialVersion,
                    'credential_fingerprint' => (string) $lockedVersion->credential_fingerprint,
                    'connection' => $lockedConnection->fresh(),
                    'version' => $lockedVersion->fresh(),
                ];
            }, 3);

            if (isset($claim['verified'])) {
                return $claim['verified'];
            }

            // A staged rotation still authenticates against the same remote
            // provider as every bound mailbox. Share the account barriers so
            // revoke/rotate, IDLE, and ordinary provider I/O cannot overlap
            // this explicit verification socket.
            try {
                $result = $this->deadline->run(fn (): array => $this->verifier->verify(
                    $this->runtimeFactory->exact($claim['connection'], $claim['version']),
                ));
            } catch (\Throwable) {
                DB::transaction(function () use ($actor, $connection, $version, $claim): void {
                    $lockedConnection = EmailProviderConnection::query()->lockForUpdate()->find($connection->getKey());
                    $lockedVersion = EmailProviderCredentialVersion::query()->lockForUpdate()->find($version->id);

                    if (! $lockedConnection
                        || ! $lockedVersion
                        || ! hash_equals((string) $lockedConnection->verification_claim_token, $claim['token'])) {
                        return;
                    }

                    $lockedConnection->forceFill([
                        'last_verification_code' => 'verification_failed',
                        'last_verified_at' => null,
                        'updated_by' => $actor->id,
                        ...$this->clearedClaim(),
                    ])->save();
                    $lockedVersion->forceFill(['verification_code' => 'verification_failed'])->save();
                    $this->events->record(
                        $lockedConnection,
                        $actor,
                        'credential_verification_failed',
                        'provider_check_failed',
                        (int) $lockedVersion->version,
                    );
                });

                // Provider/library exceptions can contain endpoints, usernames, or
                // response text. Do not retain them in the throwable chain.
                throw new EmailProviderSecurityException('provider_verification_failed');
            }

            return DB::transaction(function () use (
                $actor,
                $connection,
                $version,
                $claim,
                $result,
            ): EmailProviderCredentialVersion {
                $connection = EmailProviderConnection::query()->lockForUpdate()->findOrFail($connection->getKey());
                $version = EmailProviderCredentialVersion::query()->lockForUpdate()->findOrFail($version->id);

                if ($version->provider_integration_id !== $connection->getKey()
                    || $version->state !== EmailProviderCredentialVersion::STATE_STAGED
                    || ! hash_equals((string) $connection->verification_claim_token, $claim['token'])
                    || (int) $connection->verification_claim_configuration_version !== $claim['configuration_version']
                    || (int) $connection->verification_claim_credential_version !== $claim['credential_version']
                    || (int) $connection->configuration_version !== $claim['configuration_version']
                    || (int) $version->version !== $claim['credential_version']
                    || ! hash_equals($claim['credential_fingerprint'], (string) $version->credential_fingerprint)) {
                    throw new EmailProviderSecurityException('verification_snapshot_stale');
                }

                $capabilities = [
                    'imap' => (bool) data_get($result, 'capabilities.imap', false),
                    'smtp' => (bool) data_get($result, 'capabilities.smtp', false),
                    'folder_discovery' => (bool) data_get($result, 'capabilities.folder_discovery', false),
                ];
                $version->forceFill([
                    'verified_configuration_version' => $claim['configuration_version'],
                    'verification_code' => 'verified',
                    'verified_by' => $actor->id,
                    'verified_at' => now(),
                ])->save();
                $connection->forceFill([
                    'capabilities' => $capabilities,
                    'last_verification_code' => 'verified',
                    'last_verified_at' => now(),
                    'updated_by' => $actor->id,
                    ...$this->clearedClaim(),
                ])->save();
                $this->events->record(
                    $connection,
                    $actor,
                    'credential_verified',
                    'explicit_provider_check',
                    (int) $version->version,
                );

                return $version->fresh();
            });
        } finally {
            $this->accountLocks->release($lifecycleLocks);
        }
    }

    /** @return array<string, null> */
    private function clearedClaim(): array
    {
        return [
            'verification_claim_token' => null,
            'verification_claim_configuration_version' => null,
            'verification_claim_credential_version' => null,
            'verification_claim_expires_at' => null,
        ];
    }
}
