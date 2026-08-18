<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailMessage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class EmailProviderMessageIdentity
{
    /**
     * Build a conservative move fingerprint from facts that an IMAP move keeps stable.
     * A weak or incomplete identity returns null so deletion reconciliation fails closed.
     */
    public function forMessage(EmailMessage $message): ?string
    {
        return $this->fingerprint([
            'message_id' => $message->message_id,
            'subject' => $message->subject,
            'from_email' => $message->from_email,
            'received_at' => $message->received_at,
            'size_bytes' => $message->size_bytes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function forProviderPayload(array $payload): ?string
    {
        return $this->fingerprint($payload);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function fingerprint(array $facts): ?string
    {
        $messageId = $this->normalizeMessageId($facts['message_id'] ?? null);
        $sender = Str::lower(trim((string) ($facts['from_email'] ?? '')));
        $receivedAt = $this->normalizeDate($facts['received_at'] ?? null);
        $size = filter_var($facts['size_bytes'] ?? null, FILTER_VALIDATE_INT);

        // Message-ID alone is explicitly not a safe global identity in the Mail ADR.
        // Requiring sender, provider size and delivery instant keeps a possible move
        // ambiguous whenever the available evidence is weaker than that boundary.
        if ($messageId === '' || $sender === '' || $receivedAt === null || $size === false || $size <= 0) {
            return null;
        }

        $subject = Str::of((string) ($facts['subject'] ?? ''))
            ->squish()
            ->lower()
            ->toString();

        return hash('sha256', json_encode([
            'message_id' => $messageId,
            'subject' => $subject,
            'from_email' => $sender,
            'received_at' => $receivedAt,
            'size_bytes' => $size,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Normalize the immutable RFC Message-ID for exact provider/local checks.
     */
    public function normalizeMessageId(mixed $value): string
    {
        return Str::of((string) $value)
            ->trim()
            ->trim('<>')
            ->lower()
            ->toString();
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->clone()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return null;
        }
    }
}
