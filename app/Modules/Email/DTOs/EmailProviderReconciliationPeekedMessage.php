<?php

namespace App\Modules\Email\DTOs;

use InvalidArgumentException;
use LogicException;
use Webklex\PHPIMAP\Message;

/**
 * One exact, detached BODY.PEEK result carried only within the current worker.
 *
 * Provider message objects may retain parser state and sensitive content. The
 * envelope is deliberately non-serializable and redacts both parts from debug
 * output so a queue payload, failed-job record, or accidental dump cannot turn
 * the in-process storage seam into durable provider data.
 */
final class EmailProviderReconciliationPeekedMessage
{
    /** @var array<string, mixed> */
    private array $payload;

    private Message $message;

    /** @param array<string, mixed> $payload */
    public function __construct(
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] Message $message,
    ) {
        $payloadUid = filter_var($payload['imap_uid'] ?? null, FILTER_VALIDATE_INT);
        if ($payloadUid === false || $payloadUid < 1 || (int) $message->getUid() !== $payloadUid) {
            throw new InvalidArgumentException('The detached provider message UID does not match its payload.');
        }

        $this->payload = $payload;
        $this->message = $message;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function message(): Message
    {
        return $this->message;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Provider reconciliation PEEK results may not be serialized.');
    }

    /** @return array{payload: string, message: string} */
    public function __debugInfo(): array
    {
        return [
            'payload' => '[REDACTED]',
            'message' => '[REDACTED]',
        ];
    }
}
