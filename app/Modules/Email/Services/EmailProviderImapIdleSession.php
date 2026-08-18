<?php

namespace App\Modules\Email\Services;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\Response;

/**
 * One read-only IMAP IDLE exchange on an already connected client.
 *
 * The response is deliberately opaque. Any complete unsolicited line only
 * wakes normal reconciliation; no provider fact is projected from IDLE.
 */
final class EmailProviderImapIdleSession
{
    public function __construct(
        #[\SensitiveParameter] private readonly Client $client,
    ) {}

    public function waitForOpaqueHint(): bool
    {
        $connection = $this->client->getConnection();
        $capabilities = $connection->getCapabilities()->validatedData();
        if (! is_array($capabilities)
            || ! collect($capabilities)->contains(
                fn (mixed $capability): bool => strcasecmp((string) $capability, 'IDLE') === 0,
            )) {
            throw new EmailProviderReconciliationReadException('provider_idle_unsupported');
        }

        $state = $connection->examineFolder('INBOX')->validatedData();
        $uidValidity = is_array($state)
            ? filter_var($state['uidvalidity'] ?? null, FILTER_VALIDATE_INT)
            : false;
        if ($uidValidity === false || $uidValidity < 1) {
            throw new EmailProviderReconciliationReadException('provider_idle_examine_invalid');
        }

        $idleStarted = false;
        try {
            $connection->idle();
            $idleStarted = true;
            $line = $connection->nextLine(Response::empty());
            if (! is_string($line) || trim($line) === '') {
                throw new EmailProviderReconciliationReadException('provider_idle_hint_invalid');
            }

            return true;
        } finally {
            if ($idleStarted) {
                $connection->done();
            }
        }
    }
}
