<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Support\EmailProviderRuntimeCredentials;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;

class EmailProviderConnectionVerifier
{
    public function __construct(private readonly EmailProviderTransportFactory $transports) {}

    /** @return array{capabilities: array<string, mixed>} */
    public function verify(#[\SensitiveParameter] EmailProviderRuntimeCredentials $runtime): array
    {
        $deadline = microtime(true) + max(
            2,
            min(120, (int) config('email_provider_security.verification_deadline_seconds', 60)),
        );
        $imap = $this->transports->makeImap($runtime, $this->remainingTimeout($deadline));

        try {
            try {
                $imap->connect();
            } catch (AuthFailedException) {
                throw new EmailProviderSecurityException('provider_authentication_rejected');
            } catch (ConnectionFailedException) {
                throw new EmailProviderSecurityException('provider_connection_failed');
            }

            // Successful authenticated connect is the bounded IMAP probe.
            // Folder enumeration is intentionally excluded: a provider can
            // return an unbounded hierarchy and outlive the verification lease.
        } finally {
            try {
                $imap->disconnect();
            } catch (Throwable) {
                // Cleanup must not replace the sanitized verification result.
            }
        }

        $this->assertBeforeDeadline($deadline);
        $smtp = $this->transports->makeSmtp($runtime, $this->remainingTimeout($deadline));

        try {
            try {
                $smtp->start();
            } catch (TransportExceptionInterface) {
                throw new EmailProviderSecurityException('provider_connection_failed');
            }
        } finally {
            try {
                $smtp->stop();
            } catch (Throwable) {
                // Cleanup must not leak provider response text or mask failure.
            }
        }

        $this->assertBeforeDeadline($deadline);

        return [
            'capabilities' => [
                'imap' => true,
                'smtp' => true,
                'folder_discovery' => false,
            ],
        ];
    }

    private function remainingTimeout(float $deadline): int
    {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new EmailProviderSecurityException('provider_verification_deadline_exceeded');
        }

        return min(
            $remaining,
            max(1, min(60, (int) config('email_provider_security.connection_timeout_seconds', 20))),
        );
    }

    private function assertBeforeDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new EmailProviderSecurityException('provider_verification_deadline_exceeded');
        }
    }
}
