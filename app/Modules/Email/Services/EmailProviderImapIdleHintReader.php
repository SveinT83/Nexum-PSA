<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Contracts\EmailProviderIdleHintReader;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Services\EmailProviderTransportFactory;
use Throwable;
use Webklex\PHPIMAP\Client;

/**
 * Bounded production IDLE hint reader using the secured current runtime.
 *
 * It selects INBOX with EXAMINE, never SELECT, and always sends DONE before a
 * successful disconnect. A normal absolute deadline means no hint; cleanup or
 * protocol failures remain retryable sanitized exceptions.
 */
final class EmailProviderImapIdleHintReader implements EmailProviderIdleHintReader
{
    private const MAX_HINT_SECONDS = 25;

    public function __construct(
        private readonly EmailAccountProviderRuntimeResolver $runtimes,
        private readonly EmailProviderTransportFactory $transports,
        private readonly EmailProviderReconciliationDeadline $deadline,
    ) {}

    public function waitForOpaqueHint(
        int $accountId,
        int $expectedBindingVersion,
        int $maxSeconds,
    ): bool {
        if ($accountId < 1 || $expectedBindingVersion < 1 || $maxSeconds < 1) {
            throw new EmailProviderReconciliationReadException('provider_idle_scope_invalid');
        }
        $seconds = min(self::MAX_HINT_SECONDS, $maxSeconds);

        try {
            return $this->deadline->run($seconds, function () use (
                $accountId,
                $expectedBindingVersion,
                $seconds,
            ): bool {
                $client = null;
                $hinted = false;
                $failure = null;
                try {
                    $account = EmailAccount::query()->find($accountId);
                    if (! $account) {
                        throw new EmailProviderReconciliationReadException('email_account_missing');
                    }
                    $runtime = $this->runtimes->resolve($account, $expectedBindingVersion);
                    $prototype = $this->transports->makeImap($runtime, $seconds);
                    $client = new EmailProviderReconciliationImapClient(
                        $prototype->getConfig(),
                    );
                    unset($prototype);
                    $client->connect();
                    $hinted = (new EmailProviderImapIdleSession($client))->waitForOpaqueHint();
                } catch (Throwable $exception) {
                    $failure = $exception;
                }

                if ($client instanceof Client) {
                    try {
                        $client->disconnect();
                    } catch (EmailProviderReconciliationReadException $exception) {
                        if ($exception->safeCode === 'provider_reconciliation_cleanup_deadline_exceeded'
                            || $failure === null) {
                            $failure = $exception;
                        }
                    } catch (Throwable) {
                        if ($failure === null) {
                            $failure = new EmailProviderReconciliationReadException(
                                'provider_idle_disconnect_failed',
                            );
                        }
                    }
                }

                if ($failure instanceof Throwable) {
                    throw $failure;
                }

                return $hinted;
            });
        } catch (EmailProviderReconciliationReadException $exception) {
            if ($exception->safeCode === 'provider_reconciliation_deadline_exceeded') {
                return false;
            }

            throw $exception;
        } catch (EmailProviderSecurityException $exception) {
            throw new EmailProviderReconciliationReadException($exception->reasonCode);
        } catch (Throwable) {
            throw new EmailProviderReconciliationReadException('provider_idle_read_failed');
        }
    }
}
