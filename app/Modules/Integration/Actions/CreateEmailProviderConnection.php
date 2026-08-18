<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Services\EmailProviderAuthenticationPolicy;
use App\Modules\Integration\Services\EmailProviderEndpointPolicy;
use App\Modules\Integration\Services\EmailProviderEventRecorder;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateEmailProviderConnection
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
        private readonly EmailProviderEndpointPolicy $endpoints,
        private readonly EmailProviderAuthenticationPolicy $authentication,
        private readonly StageEmailProviderCredential $stageCredential,
        private readonly EmailProviderEventRecorder $events,
    ) {}

    /**
     * Saving is deliberately local-only. DNS and provider calls happen only
     * through the explicit Verify action.
     *
     * @param  array<string, mixed>  $input
     */
    public function execute(
        #[\SensitiveParameter] User $actor,
        #[\SensitiveParameter] array $input,
    ): EmailProviderConnection {
        $trustMode = (string) ($input['trust_mode'] ?? 'public');
        $reason = trim((string) ($input['private_endpoint_reason'] ?? ''));
        $cidrName = trim((string) ($input['trusted_cidr_name'] ?? ''));
        $actor = $trustMode === 'trusted_private'
            ? $this->authorization->authorizePrivateEndpoint($actor, $reason, $cidrName)
            : $this->authorization->authorizeProvider($actor, true);

        if ($trustMode === 'trusted_private') {
            $cidrs = config('email_provider_security.trusted_private_cidrs.'.$cidrName);

            if ((! is_array($cidrs) && ! is_string($cidrs)) || blank($cidrs)) {
                throw new EmailProviderSecurityException('trusted_cidr_unknown');
            }
        } elseif ($trustMode !== 'public') {
            throw new EmailProviderSecurityException('trust_mode_invalid');
        }

        $imap = $this->endpoints->normalize(
            'imap',
            (string) ($input['imap_host'] ?? ''),
            (int) ($input['imap_port'] ?? 0),
            (string) ($input['imap_transport'] ?? ''),
        );
        $smtp = $this->endpoints->normalize(
            'smtp',
            (string) ($input['smtp_host'] ?? ''),
            (int) ($input['smtp_port'] ?? 0),
            (string) ($input['smtp_transport'] ?? ''),
        );
        $imapAuthType = $this->authentication->normalize(
            'imap',
            (string) ($input['imap_auth_type'] ?? 'password'),
        );
        $smtpAuthType = $this->authentication->normalize(
            'smtp',
            (string) ($input['smtp_auth_type'] ?? 'password'),
        );
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 120) {
            throw new EmailProviderSecurityException('provider_name_invalid');
        }

        try {
            return DB::transaction(function () use ($actor, $input, $imap, $smtp, $imapAuthType, $smtpAuthType, $trustMode, $cidrName, $reason, $name): EmailProviderConnection {
                $integration = Integration::query()->create([
                    'id' => (string) Str::uuid(),
                    'name' => $name,
                    'type' => 'email_provider',
                    'server' => null,
                    'status' => 'disabled',
                    'config' => ['provider_status' => 'staged'],
                    'secrets' => null,
                    'last_sync_at' => null,
                    'last_error' => null,
                    'is_healthy' => false,
                ]);

                $connection = EmailProviderConnection::query()->create([
                    'integration_id' => $integration->id,
                    'driver' => 'imap_smtp',
                    'status' => 'staged',
                    'configuration_version' => 1,
                    'imap_host' => $imap->host(),
                    'imap_port' => $imap->port(),
                    'imap_transport' => $imap->transport(),
                    'imap_endpoint_policy_id' => $imap->policyIdentifier(),
                    'imap_auth_type' => $imapAuthType,
                    'smtp_host' => $smtp->host(),
                    'smtp_port' => $smtp->port(),
                    'smtp_transport' => $smtp->transport(),
                    'smtp_endpoint_policy_id' => $smtp->policyIdentifier(),
                    'smtp_auth_type' => $smtpAuthType,
                    'trust_mode' => $trustMode,
                    'trusted_cidr_name' => $trustMode === 'trusted_private' ? $cidrName : null,
                    'private_endpoint_reason' => $trustMode === 'trusted_private' ? $reason : null,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $this->stageCredential->execute($actor, $connection, [
                    'imap_username' => (string) ($input['imap_username'] ?? ''),
                    'imap_secret' => (string) ($input['imap_secret'] ?? ''),
                    'smtp_username' => (string) ($input['smtp_username'] ?? ''),
                    'smtp_secret' => (string) ($input['smtp_secret'] ?? ''),
                ]);
                $this->events->record(
                    $connection,
                    $actor,
                    'connection_created',
                    $trustMode === 'trusted_private' ? 'trusted_private_approved' : 'public_endpoint',
                    operationKey: 'connection-created:'.$connection->getKey()
                        .':'.$imap->policyIdentifier().':'.$smtp->policyIdentifier(),
                );

                return $connection->fresh(['integration', 'credentialVersions']);
            });
        } catch (QueryException) {
            // SQL bindings can contain endpoint, trust-reason, username, and
            // ciphertext material. Never retain the database exception chain.
            throw new EmailProviderSecurityException('provider_connection_persistence_failed');
        }
    }
}
