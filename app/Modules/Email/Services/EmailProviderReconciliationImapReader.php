<?php

namespace App\Modules\Email\Services;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Contracts\EmailProviderReconciliationReader;
use App\Modules\Email\DTOs\EmailProviderReconciliationBindingSnapshot;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderState;
use App\Modules\Email\DTOs\EmailProviderReconciliationMetadataPage;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderTransportFactory;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use Closure;
use Throwable;
use Webklex\PHPIMAP\Client;

/**
 * Resolve the current secured runtime and expose only bounded IMAP reads.
 *
 * A new connection is used for each interface call. Queue work therefore
 * revalidates the positive binding and current credential at execution time,
 * while the durable fingerprint deliberately survives secret-only rotation.
 */
final class EmailProviderReconciliationImapReader implements EmailProviderReconciliationReader
{
    public function __construct(
        private readonly EmailAccountProviderRuntimeResolver $runtimes,
        private readonly EmailProviderTransportFactory $transports,
        private readonly EmailProviderReconciliationFingerprint $fingerprints,
        private readonly EmailProviderReconciliationMessagePayload $payloads,
        private readonly EmailProviderReconciliationPolicy $policy,
        private readonly EmailProviderReconciliationDeadline $deadline,
    ) {}

    public function binding(
        int $accountId,
        int $expectedBindingVersion,
    ): EmailProviderReconciliationBindingSnapshot {
        try {
            return $this->deadline->run(
                EmailProviderReconciliationPolicy::PROVIDER_TIME_CAP_SECONDS,
                function () use ($accountId, $expectedBindingVersion): EmailProviderReconciliationBindingSnapshot {
                    $runtime = $this->runtime($accountId, $expectedBindingVersion);

                    return new EmailProviderReconciliationBindingSnapshot(
                        bindingVersion: $expectedBindingVersion,
                        configurationVersion: $runtime->configurationVersion(),
                        credentialVersion: $runtime->credentialVersion(),
                        runtimeFingerprint: $this->fingerprints->make([
                            'provider_integration_id_hash' => hash(
                                'sha256',
                                $runtime->providerIntegrationId(),
                            ),
                            'configuration_version' => $runtime->configurationVersion(),
                            'imap_endpoint' => $runtime->imapEndpoint()->endpoint()->toSafeArray(),
                            'imap_auth_type' => $runtime->imapAuthType(),
                            'imap_username_hash' => hash('sha256', $runtime->imapUsername()),
                        ]),
                    );
                },
            );
        } catch (EmailProviderReconciliationReadException $exception) {
            throw $exception;
        } catch (EmailProviderSecurityException $exception) {
            throw new EmailProviderReconciliationReadException($exception->reasonCode);
        } catch (Throwable) {
            throw new EmailProviderReconciliationReadException('provider_binding_resolution_failed');
        }
    }

    public function discoverFolders(
        int $accountId,
        int $expectedBindingVersion,
        int $timeCapSeconds,
    ): array {
        return $this->withSession(
            $accountId,
            $expectedBindingVersion,
            $timeCapSeconds,
            fn (EmailProviderReconciliationImapSession $session): array => $session->discoverFolders(),
        );
    }

    public function folderState(
        int $accountId,
        int $expectedBindingVersion,
        #[\SensitiveParameter]
        string $folderPath,
        int $timeCapSeconds,
    ): EmailProviderReconciliationFolderState {
        return $this->withSession(
            $accountId,
            $expectedBindingVersion,
            $timeCapSeconds,
            fn (EmailProviderReconciliationImapSession $session): EmailProviderReconciliationFolderState => $session->folderState($folderPath),
        );
    }

    public function metadataPage(
        int $accountId,
        int $expectedBindingVersion,
        #[\SensitiveParameter]
        string $folderPath,
        int $uidValidity,
        int $afterUid,
        int $throughUid,
        int $limit,
        int $timeCapSeconds,
    ): EmailProviderReconciliationMetadataPage {
        return $this->withSession(
            $accountId,
            $expectedBindingVersion,
            $timeCapSeconds,
            fn (EmailProviderReconciliationImapSession $session): EmailProviderReconciliationMetadataPage => $session->metadataPage(
                $folderPath,
                $uidValidity,
                $afterUid,
                $throughUid,
                $limit,
            ),
        );
    }

    public function messageByUidPeek(
        int $accountId,
        int $expectedBindingVersion,
        #[\SensitiveParameter]
        string $folderPath,
        int $uidValidity,
        int $uid,
        int $timeCapSeconds,
    ): ?EmailProviderReconciliationPeekedMessage {
        return $this->withSession(
            $accountId,
            $expectedBindingVersion,
            $timeCapSeconds,
            fn (EmailProviderReconciliationImapSession $session): ?EmailProviderReconciliationPeekedMessage => $session->messageByUidPeek(
                $accountId,
                $expectedBindingVersion,
                $folderPath,
                $uidValidity,
                $uid,
            ),
        );
    }

    private function runtime(
        int $accountId,
        int $expectedBindingVersion,
    ): EmailProviderRuntimeCredentials {
        if ($accountId < 1 || $expectedBindingVersion < 1) {
            throw new EmailProviderReconciliationReadException('provider_binding_invalid');
        }
        $account = EmailAccount::query()->find($accountId);
        if (! $account) {
            throw new EmailProviderReconciliationReadException('email_account_missing');
        }

        return $this->runtimes->resolve($account, $expectedBindingVersion);
    }

    /**
     * @template TResult
     *
     * @param  Closure(EmailProviderReconciliationImapSession): TResult  $callback
     * @return TResult
     */
    private function withSession(
        int $accountId,
        int $expectedBindingVersion,
        int $timeCapSeconds,
        #[\SensitiveParameter] Closure $callback,
    ): mixed {
        if ($timeCapSeconds < 1) {
            throw new EmailProviderReconciliationReadException('provider_time_cap_invalid');
        }

        try {
            return $this->deadline->run($timeCapSeconds, function () use (
                $accountId,
                $expectedBindingVersion,
                $timeCapSeconds,
                $callback,
            ): mixed {
                $client = null;
                $result = null;
                $failure = null;
                try {
                    $runtime = $this->runtime($accountId, $expectedBindingVersion);
                    $prototype = $this->transports->makeImap($runtime, $timeCapSeconds);
                    $client = new EmailProviderReconciliationImapClient(
                        $prototype->getConfig(),
                    );
                    unset($prototype);
                    $client->connect();

                    $result = $callback(new EmailProviderReconciliationImapSession(
                        $client,
                        $this->payloads,
                        $this->effectiveBodyByteCap(),
                    ));
                } catch (Throwable $exception) {
                    $failure = $exception;
                }

                if ($client instanceof Client) {
                    try {
                        $client->disconnect();
                    } catch (EmailProviderReconciliationReadException $exception) {
                        // A second alarm is the strongest evidence: cleanup
                        // exceeded its own grace even if the read had already
                        // failed. Other cleanup failures replace only success.
                        if ($exception->safeCode === 'provider_reconciliation_cleanup_deadline_exceeded'
                            || $failure === null) {
                            $failure = $exception;
                        }
                    } catch (Throwable) {
                        if ($failure === null) {
                            $failure = new EmailProviderReconciliationReadException(
                                'provider_reconciliation_disconnect_failed',
                            );
                        }
                    }
                }

                if ($failure instanceof Throwable) {
                    throw $failure;
                }

                return $result;
            });
        } catch (EmailProviderReconciliationReadException $exception) {
            throw $exception;
        } catch (EmailProviderSecurityException $exception) {
            throw new EmailProviderReconciliationReadException($exception->reasonCode);
        } catch (Throwable) {
            throw new EmailProviderReconciliationReadException('provider_reconciliation_read_failed');
        }
    }

    private function effectiveBodyByteCap(): int
    {
        $configured = filter_var(
            CommonSetting::query()
                ->where('type', 'emailhub')
                ->where('name', 'size_limit_mb')
                ->value('value'),
            FILTER_VALIDATE_INT,
        );

        return $this->policy->bodyByteCap($configured === false ? null : $configured);
    }
}
