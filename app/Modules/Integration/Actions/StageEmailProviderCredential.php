<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Services\EmailProviderAuthenticationPolicy;
use App\Modules\Integration\Services\EmailProviderCredentialCipher;
use App\Modules\Integration\Services\EmailProviderCredentialFingerprint;
use App\Modules\Integration\Services\EmailProviderEventRecorder;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class StageEmailProviderCredential
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderAuthenticationPolicy $authentication,
        private readonly EmailProviderCredentialCipher $cipher,
        private readonly EmailProviderCredentialFingerprint $fingerprint,
        private readonly EmailProviderEventRecorder $events,
    ) {}

    /**
     * @param  array{imap_username:string,imap_secret:string,smtp_username:string,smtp_secret:string}  $credentials
     */
    public function execute(
        #[\SensitiveParameter] User $actor,
        #[\SensitiveParameter] EmailProviderConnection $connection,
        #[\SensitiveParameter] array $credentials,
    ): EmailProviderCredentialVersion {
        $connection = EmailProviderConnection::query()->findOrFail($connection->getKey());
        $actor = $this->authorization->authorizeConnectionTrust($actor, $connection);

        try {
            return DB::transaction(function () use ($actor, $connection, $credentials): EmailProviderCredentialVersion {
                $connection = EmailProviderConnection::query()
                    ->with('activeCredentialVersion')
                    ->lockForUpdate()
                    ->findOrFail($connection->getKey());
                $actor = $this->authorization->authorizeConnectionTrust($actor, $connection);
                $this->authentication->normalize('imap', (string) $connection->imap_auth_type);
                $this->authentication->normalize('smtp', (string) $connection->smtp_auth_type);
                $nextVersion = ((int) EmailProviderCredentialVersion::query()
                    ->where('provider_integration_id', $connection->getKey())
                    ->lockForUpdate()
                    ->max('version')) + 1;
                $credentials = $this->rotationCredentials(
                    $connection,
                    $credentials,
                    $nextVersion > 1,
                );
                $encrypted = $this->cipher->encrypt($credentials);

                $version = EmailProviderCredentialVersion::query()->create([
                    'provider_integration_id' => $connection->getKey(),
                    'version' => $nextVersion,
                    'state' => EmailProviderCredentialVersion::STATE_STAGED,
                    ...$encrypted,
                    'credential_fingerprint' => $this->fingerprint->make($credentials),
                    'staged_by' => $actor->id,
                    'staged_at' => now(),
                ]);

                $this->events->record(
                    $connection,
                    $actor,
                    'credential_staged',
                    'explicit_stage',
                    $nextVersion,
                    'credential-staged:'.$connection->getKey().':'.$nextVersion,
                );

                return $version;
            }, 3);
        } catch (QueryException) {
            // SQL diagnostics may embed encrypted bindings. Never retain the
            // lower exception or its SQL/binding context in the public chain.
            throw new \App\Modules\Integration\Exceptions\EmailProviderSecurityException(
                'credential_stage_persistence_failed',
            );
        }
    }

    /**
     * Secret rotation preserves the established username identity. Changing a
     * username requires a new connection and explicit account rebind/rebaseline.
     *
     * @param  array{imap_username:string,imap_secret:string,smtp_username:string,smtp_secret:string}  $credentials
     * @return array{imap_username:string,imap_secret:string,smtp_username:string,smtp_secret:string}
     */
    private function rotationCredentials(
        #[\SensitiveParameter] EmailProviderConnection $connection,
        #[\SensitiveParameter] array $credentials,
        bool $hasCredentialHistory,
    ): array {
        $active = $connection->activeCredentialVersion;
        if (! $active) {
            // Only a brand-new connection may establish username identity.
            // After revoke (or before the first activation), history without
            // an active identity is deliberately not reusable: recreating and
            // rebinding the connection is what bumps account binding versions
            // and forces mailbox rebaseline for a different mailbox identity.
            if ($hasCredentialHistory) {
                throw new \App\Modules\Integration\Exceptions\EmailProviderSecurityException(
                    'credential_identity_change_requires_new_connection',
                );
            }

            return $credentials;
        }

        $current = $this->cipher->decrypt($active);
        try {
            foreach (['imap_username', 'smtp_username'] as $field) {
                if (filled($credentials[$field] ?? null)
                    && ! hash_equals((string) $current[$field], (string) $credentials[$field])) {
                    throw new \App\Modules\Integration\Exceptions\EmailProviderSecurityException(
                        'credential_identity_change_requires_new_connection',
                    );
                }

                $credentials[$field] = (string) $current[$field];
            }

            return $credentials;
        } finally {
            foreach ($current as &$value) {
                if (is_string($value) && function_exists('sodium_memzero')) {
                    sodium_memzero($value);
                }
            }
            unset($value, $current);
        }
    }
}
