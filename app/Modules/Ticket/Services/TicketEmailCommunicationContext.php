<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Ticket\Models\Ticket;

class TicketEmailCommunicationContext
{
    /** @param list<array{email: string, name?: string}> $to @param list<array{email: string, name?: string}> $cc */
    public function recipientFingerprint(array $to, array $cc): string
    {
        return $this->hash([
            'to' => collect($to)->pluck('email')->map(fn ($email) => mb_strtolower(trim((string) $email)))->unique()->sort()->values()->all(),
            'cc' => collect($cc)->pluck('email')->map(fn ($email) => mb_strtolower(trim((string) $email)))->unique()->sort()->values()->all(),
        ]);
    }

    public function threadFingerprint(array $headers): string
    {
        return $this->hash([
            'in_reply_to' => (string) ($headers['in_reply_to'] ?? ''),
            'references' => (string) ($headers['references'] ?? ''),
        ]);
    }

    public function sourceFingerprint(
        Ticket $ticket,
        EmailTicketConversationLink $link,
        EmailMailboxPlacement $placement,
        int $providerBindingVersion,
    ): string {
        return $this->hash([
            'ticket_id' => (int) $ticket->id,
            'ticket_updated_at' => $ticket->updated_at?->format('Y-m-d H:i:s'),
            'relationship_id' => (int) $link->id,
            'relationship_updated_at' => $link->updated_at?->format('Y-m-d H:i:s'),
            'relationship_status' => $link->status,
            'relationship_audience' => $link->audience,
            'account_id' => (int) $placement->account_id,
            'conversation_id' => $placement->email_conversation_id ? (int) $placement->email_conversation_id : null,
            'source_message_id' => (int) $placement->email_message_id,
            'source_placement_id' => (int) $placement->id,
            'source_sync_version' => (int) $placement->sync_version,
            'provider_binding_version' => $providerBindingVersion,
        ]);
    }

    /** @return array{in_reply_to: string|null, references: string|null} */
    public function replyHeaders(\App\Modules\Email\Models\EmailMessage $source): array
    {
        $sourceMessageId = trim((string) $source->message_id);
        $references = collect(preg_split('/\s+/', (string) $source->references) ?: [])
            ->map(fn (string $reference): string => trim($reference))
            ->filter();

        if ($sourceMessageId !== '') {
            $references->push($sourceMessageId);
        }

        return [
            'in_reply_to' => $sourceMessageId !== '' ? $sourceMessageId : null,
            'references' => $references->unique()->implode(' ') ?: null,
        ];
    }

    public function subjectWithTicketKey(string $subject, Ticket $ticket): string
    {
        $subject = trim($subject);

        if (preg_match('/\b'.preg_quote($ticket->ticket_key, '/').'\b/i', $subject)) {
            return $subject;
        }

        return trim($subject.' ['.$ticket->ticket_key.']');
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
